<?php

namespace App\Services\Products;

use App\Filament\Support\ProductOptions;
use App\Jobs\RunProductFormAssistant;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A restricted proposal editor: never saves products, publishes, or executes AI commands. */
class ProductFormAssistant
{
    public const KIND = 'filament_product_form';

    public const TIMEOUT = 300;

    public function authorize(User $user, ?Product $product): void
    {
        abort_unless($user->fresh()?->canAccessPanel(Filament::getPanel('admin')), 403);
        abort_if($product && ! Product::whereKey($product->id)->exists(), 404);
        $ability = $product ? 'update' : 'create';
        $subject = $product ?? Product::class;
        if (Gate::getPolicyFor(Product::class)) {
            Gate::forUser($user)->authorize($ability, $subject);
        }
    }

    public function start(User $user, ?Product $product, string $session, string $mode, string $message, array $form, array $history, ?string $variantKey = null, array $pendingCatalog = []): AiRun
    {
        $this->authorize($user, $product);
        abort_unless(in_array($mode, ['chat', 'translate', 'enrich'], true), 422);
        $base = $this->snapshot($form);
        $pendingCatalog = array_map(fn (array $proposal): array => Arr::only($proposal, ['kind', 'label', 'key', 'data_type', 'unit', 'value', 'group', 'product_type']), $pendingCatalog);
        if (strlen(json_encode([$base, $pendingCatalog])) > 150000) {
            throw ValidationException::withMessages(['productAiMessage' => __('product-assistant.invalid_response')]);
        }
        $settings = app(AiSettings::class);

        return Cache::lock('product-form-ai:'.$user->id.':'.$session, 10)->block(3, function () use ($user, $product, $session, $mode, $message, $base, $history, $variantKey, $settings, $pendingCatalog): AiRun {
            $existing = AiRun::query()->where('activity->kind', self::KIND)->where('activity->user_id', $user->id)
                ->where('activity->session_id', $session)->whereIn('status', ['queued', 'running'])
                ->where('created_at', '>=', now()->subSeconds(self::TIMEOUT + 45))->latest('id')->first();
            if ($existing) {
                return $existing;
            }
            $run = AiRun::create([
                'provider' => $settings->providerFor('server_assistant'),
                'model' => $settings->modelFor('server_assistant'),
                'status' => 'queued', 'started_at' => now(),
                'prompt' => $message,
                'activity' => [
                    'kind' => self::KIND, 'user_id' => $user->id, 'session_id' => $session,
                    'product_id' => $product?->id, 'mode' => $mode, 'base' => $base,
                    'history' => array_slice($history, -12), 'locale' => app()->getLocale(),
                    'pending_catalog' => $pendingCatalog,
                    'variant_key' => $variantKey && isset($base['variants'][$variantKey]) ? $variantKey : $this->selectedVariant($base),
                ],
            ]);
            // Process-only launcher flag stays effective with cached Laravel
            // configuration. The local admin worker must never consume bot jobs.
            $adminOnly = getenv('NINGREDY_ADMIN_ONLY') === '1';
            $job = RunProductFormAssistant::dispatch($run->id)->onQueue($adminOnly ? 'admin-ai' : 'assistant');
            if ($adminOnly) {
                $job->onConnection('database');
            }
            $job->afterCommit();

            return $run;
        });
    }

    public function snapshot(array $form): array
    {
        $safe = Arr::only($form, ['title', 'description', 'model', 'category_id', 'brand_id', 'translations', 'sources']);
        foreach (($form['variants'] ?? []) as $key => $variant) {
            $safe['variants'][$key] = Arr::only($variant, ['name', 'sku', 'mpn', 'gtin', 'color', 'translations', 'is_default',
                'price', 'currency', 'compare_at_price', 'condition', 'quantity', 'stock_status', 'warranty_months']);
            foreach (($variant['attributes'] ?? []) as $rowKey => $row) {
                $safe['variants'][$key]['attributes'][$rowKey] = Arr::only($row, ['attribute_definition_id', 'value', 'unit', 'translations']);
            }
        }

        return $safe;
    }

    public function selectedVariant(array $form): ?string
    {
        foreach ($form['variants'] ?? [] as $key => $variant) {
            if ($variant['is_default'] ?? false) {
                return (string) $key;
            }
        }

        return isset($form['variants']) ? (string) array_key_first($form['variants']) : null;
    }

    public function prompt(AiRun $run): string
    {
        return json_encode([
            'mode' => $run->activity['mode'], 'interface_language' => $run->activity['locale'],
            'operator_message' => $run->prompt, 'selected_variant_key' => $run->activity['variant_key'],
            'form' => $run->activity['base'], 'recent_chat' => $run->activity['history'],
            'pending_catalog' => $run->activity['pending_catalog'] ?? [],
            'commercial_options' => ['conditions' => array_keys(ProductCondition::options()), 'stock_statuses' => array_keys(ProductOptions::stockStatuses())],
            'brands' => Brand::query()->get(['id', 'name'])->toArray(),
            'categories' => Category::query()->get(['id', 'name', 'product_type_affinity'])->toArray(),
            'attributes' => AttributeDefinition::query()->get(['id', 'key', 'label', 'default_unit', 'data_type', 'attribute_group_id'])->toArray(),
            'groups' => AttributeGroup::query()->get(['id', 'name', 'translations'])->toArray(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function normalize(array $response): array
    {
        $data = Validator::make($response, [
            'message' => 'required|string|max:8000', 'changes' => 'present|array|max:250',
            'changes.*.path' => 'required|string|max:300', 'changes.*.value' => 'present|string|max:10000',
            'attributes' => 'present|array|max:100', 'attributes.*.variant_key' => 'required|string|max:100',
            'attributes.*.row_key' => 'nullable|string|max:100', 'attributes.*.definition_id' => 'nullable|integer|min:1',
            'attributes.*.label' => 'required|string|max:255', 'attributes.*.key' => 'present|string|max:255',
            'attributes.*.value' => 'required|string|max:2000', 'attributes.*.unit' => 'nullable|string|max:24',
            'attributes.*.data_type' => 'required|in:text,number,boolean', 'attributes.*.group' => 'nullable|array',
            'catalog' => 'present|array|max:2', 'catalog.*.kind' => 'required|in:brand,category',
            'catalog.*.name' => 'required|string|max:255', 'catalog.*.product_type' => 'nullable|string|max:30',
            'sources' => 'present|array|max:20', 'sources.*.title' => 'required|string|max:500',
            'sources.*.url' => 'required|string|max:2048',
        ])->validate();
        $data['sources'] = array_values(array_filter($data['sources'], fn (array $source): bool => $this->safeUrl($source['url'])));

        return $data;
    }

    public function safeUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)
            || ! str_contains($host, '.') || preg_match('/(?:^|\.)(localhost|local|internal|test|invalid)$/i', $host)
            || preg_match('/^[\d.]+$/', $host) || str_contains($host, ':')) {
            return false;
        }

        return true;
    }

    /** Merge against the request snapshot, preserving edits made while the provider was busy. */
    public function apply(AiRun $run, array $form, array $pending): array
    {
        $response = $this->normalize($run->response ?? []);
        $base = $run->activity['base'];
        $mode = $run->activity['mode'];
        $conflicts = [];
        $changed = 0;
        $originalForm = $form;
        $acceptedSources = [];
        $sourceProposals = [];
        $identityChanged = $mode !== 'translate' && ! $this->same(Arr::only($this->snapshot($form), ['title', 'model']), Arr::only($base, ['title', 'model']));
        if ($mode !== 'translate') {
            $key = $run->activity['variant_key'];
            $identityChanged = $identityChanged || ! $this->same(Arr::only($form['variants'][$key] ?? [], ['sku', 'mpn', 'gtin']), Arr::only($base['variants'][$key] ?? [], ['sku', 'mpn', 'gtin']));
        }
        if ($identityChanged) {
            return ['form' => $form, 'pending' => $pending, 'conflicts' => ['title / model / SKU'], 'changed' => 0];
        }
        // Research facts without any usable provenance remain a chat answer, never form data.
        if ($mode === 'enrich' && $response['sources'] === []) {
            return ['form' => $form, 'pending' => $pending, 'conflicts' => ['sources'], 'changed' => 0];
        }
        $applyChanges = function (array $changes) use ($run, $mode, $base, $originalForm, &$form, &$conflicts, &$changed, &$acceptedSources, &$sourceProposals): void {
            foreach ($changes as $change) {
                $path = $change['path'];
                if (! $this->allowedPath($path, $base, $mode)) {
                    continue;
                }
                if (! str_contains($path, 'translations.')) {
                    $sourceProposals[$path] = $change['value'];
                }
                if (str_starts_with($path, 'variants.')) {
                    $segments = explode('.', $path);
                    if ($mode !== 'translate' && ! str_contains($path, '.translations.') && $segments[1] !== $run->activity['variant_key']) {
                        continue;
                    }
                    if (! isset($form['variants'][$segments[1]]) || isset($segments[3]) && $segments[2] === 'attributes' && ! isset($form['variants'][$segments[1]]['attributes'][$segments[3]])) {
                        $conflicts[] = $path;

                        continue;
                    }
                }
                if (! $this->same(data_get($originalForm, $path), data_get($base, $path)) || ! $this->translationSourceUnchanged($path, $originalForm, $base, $acceptedSources, $form, $sourceProposals)) {
                    $conflicts[] = $path;

                    continue;
                }
                if ($mode === 'enrich' && filled(data_get($base, $path))) {
                    continue;
                }
                $value = $this->fieldValue($path, $change['value'], $run);
                if ($value === false || $this->same(data_get($form, $path), $value)) {
                    continue;
                }
                data_set($form, $path, $value);
                if (! str_contains($path, 'translations.') && filled($value)) {
                    $acceptedSources[$path] = true;
                }
                $changed++;
            }
        };
        $applyChanges(array_filter($response['changes'], fn (array $change): bool => ! str_contains($change['path'], 'translations.')));
        if ($mode !== 'translate') {
            foreach ($response['catalog'] as $proposal) {
                $kind = $proposal['kind'];
                $path = $kind.'_id';
                if ($mode === 'enrich' && filled($base[$path] ?? null) || ! $this->same($form[$path] ?? null, $base[$path] ?? null)) {
                    continue;
                }
                $class = $kind === 'brand' ? Brand::class : Category::class;
                $existing = $class::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($proposal['name']))])->first();
                $token = $existing?->id ?? 'ai:'.Str::uuid();
                if (! $existing) {
                    $pending[$token] = ['kind' => $kind, 'label' => trim($proposal['name']), 'product_type' => $proposal['product_type'] ?? 'other'];
                }
                $form[$path] = $token;
                $changed++;
            }
            foreach ($response['attributes'] as $attribute) {
                $key = $attribute['variant_key'];
                if ($key !== $run->activity['variant_key'] || ! isset($base['variants'][$key], $form['variants'][$key])) {
                    $conflicts[] = 'variants.'.$key;

                    continue;
                }
                $definition = isset($attribute['definition_id']) ? AttributeDefinition::find($attribute['definition_id']) : null;
                if (isset($attribute['definition_id']) && ! $definition) {
                    continue;
                }
                $definition ??= AttributeDefinition::where('key', $attribute['key'])->first()
                    ?? AttributeDefinition::whereRaw('LOWER(label) = ?', [mb_strtolower(trim($attribute['label']))])->first();
                $rowKey = $attribute['row_key'] ?? null;
                if ($rowKey !== null && ! isset($base['variants'][$key]['attributes'][$rowKey], $form['variants'][$key]['attributes'][$rowKey])) {
                    $conflicts[] = 'variants.'.$key.'.attributes.'.$rowKey;

                    continue;
                }
                if ($rowKey === null && $definition) {
                    foreach ($base['variants'][$key]['attributes'] ?? [] as $baseKey => $baseRow) {
                        if ((string) ($baseRow['attribute_definition_id'] ?? '') === (string) $definition->id
                            && ! isset($form['variants'][$key]['attributes'][$baseKey])) {
                            $conflicts[] = 'variants.'.$key.'.attributes.'.$baseKey;

                            continue 2;
                        }
                    }
                    foreach ($form['variants'][$key]['attributes'] ?? [] as $candidateKey => $row) {
                        if ((string) ($row['attribute_definition_id'] ?? '') === (string) $definition->id) {
                            $rowKey = $candidateKey;
                            break;
                        }
                    }
                }
                $row = $form['variants'][$key]['attributes'][$rowKey] ?? null;
                if ($rowKey !== null) {
                    $sourceProposals['variants.'.$key.'.attributes.'.$rowKey.'.value'] = $attribute['value'];
                }
                if ($row && (! $this->same(Arr::only($row, ['attribute_definition_id', 'value', 'unit', 'translations']), $base['variants'][$key]['attributes'][$rowKey] ?? null)
                    || $mode === 'enrich' && filled($row['value'] ?? null)
                    || $definition && (string) ($row['attribute_definition_id'] ?? '') !== (string) $definition->id)) {
                    $conflicts[] = 'variants.'.$key.'.attributes.'.$rowKey;

                    continue;
                }
                $token = $definition?->id;
                if (! $token) {
                    foreach ($pending as $pendingKey => $proposed) {
                        if ($proposed['kind'] === 'attribute' && (mb_strtolower($proposed['label']) === mb_strtolower(trim($attribute['label'])) || $attribute['key'] !== '' && $proposed['key'] === $attribute['key'])) {
                            $token = $pendingKey;
                            foreach ($form['variants'][$key]['attributes'] ?? [] as $candidateKey => $candidate) {
                                if (($candidate['attribute_definition_id'] ?? null) === $token) {
                                    $rowKey = $candidateKey;
                                    $row = $candidate;
                                    if ($mode === 'enrich' && filled($row['value'] ?? null) || ! $this->same(Arr::only($row, ['attribute_definition_id', 'value', 'unit', 'translations']), $base['variants'][$key]['attributes'][$rowKey] ?? null)) {
                                        $conflicts[] = 'variants.'.$key.'.attributes.'.$rowKey;

                                        continue 3;
                                    }
                                }
                            }
                            break;
                        }
                    }
                }
                if (! $token) {
                    $token = 'ai:'.Str::uuid();
                    $pending[$token] = [
                        'kind' => 'attribute', 'label' => trim($attribute['label']), 'key' => $attribute['key'],
                        'data_type' => $attribute['data_type'], 'unit' => $attribute['unit'],
                        'group' => AiAttributeGroups::normalize($attribute['group'] ?? null),
                    ];
                }
                if (isset($pending[$token])) {
                    $pending[$token]['value'] = $attribute['value'];
                    $pending[$token]['unit'] = $attribute['unit'];
                }
                $rowKey ??= (string) Str::uuid();
                $form['variants'][$key]['attributes'][$rowKey] = [...($row ?? []),
                    'attribute_definition_id' => $token, 'value' => $attribute['value'], 'unit' => $attribute['unit'], '_category_default' => false,
                ];
                $acceptedSources['variants.'.$key.'.attributes.'.$rowKey.'.value'] = true;
                $sourceProposals['variants.'.$key.'.attributes.'.$rowKey.'.value'] = $attribute['value'];
                $changed++;
            }
            foreach ($response['sources'] as $source) {
                if (collect($form['sources'] ?? [])->contains(fn ($row) => ($row['url'] ?? null) === $source['url'])) {
                    continue;
                }
                $form['sources'][(string) Str::uuid()] = [...$source, 'source_type' => 'web'];
                $changed++;
            }
        }

        $applyChanges(array_filter($response['changes'], fn (array $change): bool => str_contains($change['path'], 'translations.')));

        if (! $this->same($form['category_id'] ?? null, $base['category_id'] ?? null) && is_numeric($form['category_id'] ?? null)) {
            $form['variants'] = app(ManualProductAttributes::class)->applyCategory($form['variants'] ?? [], $form['category_id']);
        }

        return compact('form', 'pending', 'conflicts', 'changed');
    }

    private function same(mixed $left, mixed $right): bool
    {
        return is_array($left) || is_array($right) ? $left == $right : (string) $left === (string) $right;
    }

    private function allowedPath(string $path, array $base, string $mode): bool
    {
        $parts = explode('.', $path);
        $locales = array_keys(CatalogLocales::CONTENT_TRANSLATIONS);
        if (count($parts) === 3 && $parts[0] === 'translations') {
            return in_array($parts[1], $locales, true) && in_array($parts[2], ['title', 'description'], true);
        }
        if ($parts[0] === 'variants' && isset($parts[1], $base['variants'][$parts[1]])) {
            if (count($parts) === 5 && $parts[2] === 'translations') {
                return in_array($parts[3], $locales, true) && in_array($parts[4], ['title', 'description'], true);
            }
            if (count($parts) === 7 && $parts[2] === 'attributes' && isset($base['variants'][$parts[1]]['attributes'][$parts[3]]) && $parts[4] === 'translations') {
                return in_array($parts[5], $locales, true) && in_array($parts[6], ['name', 'value'], true);
            }
            if ($mode !== 'translate' && count($parts) === 3) {
                return in_array($parts[2], ['name', 'sku', 'mpn', 'gtin', 'color'], true)
                    || $mode === 'chat' && in_array($parts[2], ['price', 'currency', 'compare_at_price', 'condition', 'quantity', 'stock_status', 'warranty_months'], true);
            }
        }

        return $mode !== 'translate' && in_array($path, ['title', 'description', 'model', 'brand_id', 'category_id'], true);
    }

    private function translationSourceUnchanged(string $path, array $form, array $base, array $acceptedSources = [], array $resultForm = [], array $sourceProposals = []): bool
    {
        if (! str_contains($path, 'translations.')) {
            return true;
        }
        [$prefix, $tail] = explode('translations.', $path, 2);
        $field = explode('.', $tail)[1];
        $field = $prefix !== '' && $field === 'title' ? 'name' : $field;
        $source = $prefix.(str_contains($prefix, '.attributes.') ? 'value' : $field);
        if (array_key_exists($source, $sourceProposals) && ! $this->same(data_get($resultForm, $source), $sourceProposals[$source])) {
            return false;
        }
        if (isset($acceptedSources[$source])) {
            return true;
        }
        if (str_contains($prefix, '.attributes.')) {
            return $this->same(data_get($form, $prefix.'value'), data_get($base, $prefix.'value'))
                && $this->same(data_get($form, $prefix.'attribute_definition_id'), data_get($base, $prefix.'attribute_definition_id'));
        }

        return filled(data_get($base, $source)) && $this->same(data_get($form, $source), data_get($base, $source));
    }

    private function fieldValue(string $path, string $value, AiRun $run): string|int|float|false
    {
        $field = Str::afterLast($path, '.');
        if (in_array($path, ['category_id', 'brand_id'], true)) {
            $class = $path === 'brand_id' ? Brand::class : Category::class;

            return ctype_digit($value) && $class::whereKey($value)->exists() ? (int) $value : false;
        }
        $commercialWords = [
            'price' => 'price|cena|cen[uy]|ceně|цен[ауые]|вартіст|ціна',
            'compare_at_price' => 'old price|original price|původní cen|star[áé] cen|стар[а-я]* цен|стара цін',
            'quantity' => 'quantity|qty|stock count|množství|kus[ůy]?|количеств|штук|кількіст',
            'warranty_months' => 'warranty|záruk|гарант',
            'currency' => 'currency|měn[ay]|валют|CZK|EUR|USD|Kč',
            'condition' => 'condition|stav|used|new|refurbished|open.box|použit|nov[ýé]|rozbalen|repas|состояни|новый|б.?у|подерж|вживан',
            'stock_status' => 'stock|available|availability|sklad|dostup|наличи|наявн|предзаказ|preorder',
        ];
        if (isset($commercialWords[$field]) && ! preg_match('/(?:'.$commercialWords[$field].')/iu', (string) $run->prompt)) {
            return false;
        }
        if (in_array($field, ['price', 'compare_at_price', 'quantity', 'warranty_months'], true)) {
            $number = str_replace(',', '.', preg_replace('/\h+/u', '', $value));
            preg_match_all('/(?<![\d.])\d+(?:[ \x{00A0}\x{202F}]\d{3})*(?:[.,]\d+)?(?![\d.])/u', (string) $run->prompt, $matches);
            $operatorAmounts = array_map(fn (string $amount): float => (float) str_replace(',', '.', preg_replace('/\h+/u', '', $amount)), $matches[0]);
            if (! is_numeric($number) || (float) $number < 0 || ! in_array((float) $number, $operatorAmounts, true)) {
                return false;
            }
            if (in_array($field, ['quantity', 'warranty_months'], true) && (float) $number !== (float) (int) $number) {
                return false;
            }

            return (float) $number;
        }
        if ($field === 'condition') {
            return array_key_exists($value, ProductCondition::options()) ? $value : false;
        }
        if ($field === 'stock_status') {
            return array_key_exists($value, ProductOptions::stockStatuses()) ? $value : false;
        }
        if ($field === 'currency') {
            return preg_match('/^[A-Z]{3}$/', $value) ? $value : false;
        }
        $max = $field === 'description' ? 5000 : (str_contains($path, '.attributes.') ? 2000 : 255);

        return mb_strlen($value) <= $max ? $value : false;
    }

    /** Called only in the normal Create/Save transaction, never by an assistant request. */
    public function materialize(array $form, array $pending): array
    {
        $resolve = function (mixed $id, string $kind) use ($pending): mixed {
            if (! is_string($id) || ! str_starts_with($id, 'ai:')) {
                return $id;
            }
            $proposal = $pending[$id] ?? null;
            if (! $proposal || $proposal['kind'] !== $kind) {
                throw ValidationException::withMessages(['data' => __('product-assistant.invalid_response')]);
            }
            if ($kind === 'attribute') {
                $existing = AttributeDefinition::where('key', $proposal['key'])->first()
                    ?? AttributeDefinition::whereRaw('LOWER(label) = ?', [mb_strtolower($proposal['label'])])->first();

                return $existing?->id ?? AttributeDefinition::create([
                    'key' => preg_match('/^[a-z][a-z0-9_-]{0,254}$/', $proposal['key']) ? $proposal['key'] : null,
                    'label' => $proposal['label'], 'data_type' => $proposal['data_type'], 'default_unit' => $proposal['unit'],
                    'attribute_group_id' => AiAttributeGroups::resolve($proposal['group'])?->id,
                    'is_filterable' => AttributeDefinition::filterableByDefault($proposal['key']), 'is_variant' => false, 'sort_order' => 0,
                ])->id;
            }
            $class = $kind === 'brand' ? Brand::class : Category::class;
            $existing = $class::whereRaw('LOWER(name) = ?', [mb_strtolower($proposal['label'])])->first();
            $data = ['name' => $proposal['label']];
            if ($kind === 'category') {
                $data['product_type_affinity'] = array_key_exists($proposal['product_type'], ProductOptions::types()) ? $proposal['product_type'] : 'other';
                $data['is_active'] = true;
            }

            return $existing?->id ?? $class::create($data)->id;
        };
        foreach (['category', 'brand'] as $kind) {
            if (isset($form[$kind.'_id'])) {
                $form[$kind.'_id'] = $resolve($form[$kind.'_id'], $kind);
            }
        }
        foreach ($form['variants'] ?? [] as $key => $variant) {
            foreach ($variant['attributes'] ?? [] as $rowKey => $row) {
                if (filled($row['value'] ?? null)) {
                    $form['variants'][$key]['attributes'][$rowKey]['attribute_definition_id'] = $resolve($row['attribute_definition_id'] ?? null, 'attribute');
                }
            }
        }

        return $form;
    }
}
