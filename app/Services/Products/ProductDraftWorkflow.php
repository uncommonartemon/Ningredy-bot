<?php

namespace App\Services\Products;

use App\Exceptions\LowResolutionDraftMediaException;
use App\Exceptions\MissingDraftMediaException;
use App\Exceptions\UnreconciledDraftSpecificationsException;
use App\Exceptions\UnverifiedDraftMediaException;
use App\Jobs\StoreProductImages;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Telegram\TelegramCatalogDraftMedia;
use App\Services\Telegram\TelegramCatalogEditor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductDraftWorkflow
{
    private const FILTERABLE_ATTRIBUTE_KEYS = [
        'cpu', 'gpu', 'ram', 'storage', 'display', 'screen_size', 'refresh_rate',
    ];

    public function __construct(
        private readonly ProductPublicDescription $publicDescription,
        private readonly AiSettings $settings,
        private readonly ProductCategoryResolver $categoryResolver,
    ) {}

    public function approve(
        ProductDraft $draft,
        ?User $reviewer = null,
        ?string $telegramReviewerId = null,
    ): Product {
        try {
            return $this->doApprove($draft, $reviewer, $telegramReviewerId);
        } catch (UniqueConstraintViolationException) {
            // Two approvals for the same product raced (near-simultaneous
            // double-click/retry): the other one committed first, so
            // canonical_key now exists. Retry once; the shared identity
            // lookup now sees the committed product and its variant.
            return $this->doApprove($draft, $reviewer, $telegramReviewerId);
        }
    }

    private function assertReadyForApproval(ProductDraft $draft): void
    {
        throw_unless($draft->status === 'pending_review', \RuntimeException::class, 'Черновик уже обработан.');
        if ($draft->isManualUpload()) {
            $this->assertManualIntakeReady($draft);
        }
        $category = $draft->category
            ? Category::query()->where('slug', $draft->category)->first()
            : null;
        $minimumWidth = $category?->minimumImageWidth() ?? $this->settings->imageMinimumWidth();
        $minimumHeight = $category?->minimumImageHeight() ?? $this->settings->imageMinimumHeight();
        if ($draft->isManualUpload()) {
            // Operator photographs are a deliberate manual input, not a web gallery.
            $minimumWidth = $minimumHeight = 1;
        }
        $media = $draft->media()->lockForUpdate()->get(['width', 'height', 'verification_status']);
        throw_if(
            $media->isEmpty(),
            MissingDraftMediaException::class,
            'Черновик не содержит проверенных фотографий и не может быть опубликован.',
        );
        // The frames of a check that could not run are kept - they are real
        // photographs - but they are marked pending, and this button is the
        // last place that distinction can still be honoured. Publishing them
        // would let a Vision timeout finish as an approval, which is the one
        // thing the strategy forbids outright. The button already selected
        // verification_status and had never once looked at it.
        $unverifiedMedia = $media->first(fn ($media): bool => $media->verification_status === 'pending');
        throw_if(
            $unverifiedMedia !== null,
            UnverifiedDraftMediaException::class,
            'Фотографии черновика не прошли проверку — она не состоялась технически. Публиковать непроверенные кадры нельзя.',
        );
        $lowResolutionMedia = $media
            ->first(function ($media) use ($minimumWidth, $minimumHeight): bool {
                return (int) $media->width < $minimumWidth
                    || (int) $media->height < $minimumHeight;
            });
        throw_if(
            $lowResolutionMedia !== null,
            LowResolutionDraftMediaException::class,
            $minimumHeight > 0
                ? "Черновик содержит фото меньше {$minimumWidth}×{$minimumHeight}px и не может быть опубликован. Сначала замените фотографии."
                : "Черновик содержит фото шириной меньше {$minimumWidth}px и не может быть опубликован. Высота не ограничена. Сначала замените фотографии.",
        );
        // The card's specifications, sources, and photos must describe one
        // exact configuration (PROJECT_STRATEGY.md, "Единый источник
        // карточки") - a photo source chosen after the operator's request
        // did not pin an exact SKU can genuinely be a different, still-
        // suitable configuration than whatever specifications research
        // originally wrote down, and ProductSpecificationReconciler is what
        // checks the two actually agree. Independent of gallery_status: a
        // 'partial' gallery is already publishable today, so this has to
        // gate on its own persisted fact, not on that column.
        throw_if(
            $draft->reconciliationPending(),
            UnreconciledDraftSpecificationsException::class,
            'Характеристики черновика не сверены с источником выбранных фотографий — публикация невозможна, пока сверка не завершится.',
        );
    }

    private function doApprove(
        ProductDraft $draft,
        ?User $reviewer,
        ?string $telegramReviewerId,
    ): Product {
        return DB::transaction(function () use ($draft, $reviewer, $telegramReviewerId): Product {
            $draft = ProductDraft::query()->lockForUpdate()->with(['telegramUpdate', 'media'])->findOrFail($draft->id);
            $this->assertReadyForApproval($draft);
            $normalizedDescription = $this->publicDescription->normalize([
                'title' => $draft->title,
                'model' => $draft->model,
                'color' => $draft->color,
                'description' => $draft->description,
                'research_notes' => $draft->research_notes,
                'specifications' => $draft->specifications,
            ]);
            $draft->update($normalizedDescription);
            $productType = in_array($draft->product_type, ['laptop', 'desktop', 'component', 'other'], true)
                ? $draft->product_type
                : $this->guessProductType($draft);
            $category = $this->categoryResolver->resolve($productType, $draft->category);
            throw_unless(
                $category,
                \RuntimeException::class,
                'No single active category is configured for product type '.$productType.'.',
            );

            $brand = $this->resolveBrand($draft->brand);
            $catalogIdentity = app(ProductCatalogIdentity::class);
            $card = $draft->only(['brand', 'model', 'title', 'specifications']);
            $targetId = $draft->isManualUpload() ? data_get($draft->manualIntake(), 'target_product_id') : null;
            if ($targetId) {
                $product = Product::query()->lockForUpdate()->findOrFail($targetId);
                $variantId = data_get($draft->manualIntake(), 'target_variant_id');
                $variant = $variantId ? $product->variants()->lockForUpdate()->findOrFail($variantId) : null;
                throw_unless(hash_equals((string) data_get($draft->manualIntake(), 'catalog_fingerprint'),
                    app(TelegramCatalogEditor::class)->fingerprint($product, $variant)),
                    \RuntimeException::class, 'Товар изменён после открытия редактора. Откройте актуальную карточку заново.');
                $other = $catalogIdentity->find($card);
                throw_if($other && $other->id !== $product->id, \RuntimeException::class,
                    'Изменённая идентичность совпадает с другим товаром каталога. Объединение требует отдельного решения.');
                if ($product->title !== $draft->title || $product->model !== $draft->model
                    || $product->brand?->name !== $draft->brand
                    || ($variant && array_filter($variant->only(['sku', 'mpn', 'gtin'])) !== ProductCatalogIdentity::identifiers($draft->specifications ?? []))) {
                    $product->canonical_key = $catalogIdentity->keyForNewProduct($card);
                }
            } else {
                $product = $catalogIdentity->find($card);
                throw_if($draft->isManualUpload() && $product !== null, \RuntimeException::class,
                    'Такой товар уже есть в каталоге. Откройте его через /edit вместо повторного добавления.');
                $product ??= new Product(['canonical_key' => $catalogIdentity->keyForNewProduct($card)]);
            }

            if (! $product->exists) {
                $product->slug = $this->uniqueSlug($draft);
            }

            $product->fill([
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'product_type' => $productType,
                'status' => $targetId ? $product->status : 'published',
                'title' => $draft->title,
                'model' => $draft->model,
                'description' => $normalizedDescription['description'],
                'translations' => CatalogLocales::textTranslations($draft->translations),
                'confidence' => max((float) $product->confidence, (float) $draft->confidence),
                'is_active' => $targetId ? $product->is_active : true,
                'published_at' => $product->published_at ?: now(),
            ])->save();

            $variant = $this->upsertVariant($product, $draft);
            $unchangedGlobalKeys = [];
            if ($targetId) {
                $keys = collect($draft->specifications ?? [])->map(fn ($spec) => $spec['key'] ?? $this->canonicalAttributeKey($spec['name']))->all();
                $variant->attributes()->whereHas('definition', fn ($definition) => $definition->whereNotIn('key', $keys))->delete();
                $unchangedGlobalKeys = $this->preserveOtherVariantAttributes($product, $variant, $draft->specifications ?? []);
            }
            $this->syncAttributes($product, $variant, $category, $draft->specifications ?? [],
                CatalogLocales::specificationTranslations($draft->translations), (bool) $targetId, $unchangedGlobalKeys);
            $this->syncSources($product, $variant, $draft);

            $draft->update([
                'status' => 'approved',
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $reviewer?->id,
                'reviewed_by_telegram_user_id' => $telegramReviewerId,
                'rejection_reason' => null,
                'approved_product_id' => $product->id,
                'approved_variant_id' => $variant->id,
            ]);

            if ($targetId) {
                $origin = $draft->telegramUpdate()->lockForUpdate()->firstOrFail();
                $payload = $origin->payload ?? [];
                $payload['_manual_product_intake']['approved_catalog_fingerprint'] = app(TelegramCatalogEditor::class)->fingerprint($product->fresh(), $variant->fresh());
                $payload['_manual_product_intake']['approved_draft_media_fingerprint'] = app(TelegramCatalogDraftMedia::class)->galleryFingerprint($draft);
                $origin->update(['payload' => $payload]);
            }

            StoreProductImages::dispatch($product->id, $variant->id, $draft->id)->afterCommit();

            return $product;
        });
    }

    public function reject(
        ProductDraft $draft,
        ?User $reviewer = null,
        ?string $telegramReviewerId = null,
        ?string $reason = null,
    ): void {
        DB::transaction(function () use ($draft, $reviewer, $telegramReviewerId, $reason): void {
            $draft = ProductDraft::query()->lockForUpdate()->findOrFail($draft->id);
            throw_unless($draft->status === 'pending_review', \RuntimeException::class, 'Черновик уже обработан.');
            $draft->update([
                'status' => 'rejected',
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $reviewer?->id,
                'reviewed_by_telegram_user_id' => $telegramReviewerId,
                'rejection_reason' => $reason,
            ]);
        });
    }

    private function resolveBrand(?string $name): ?Brand
    {
        if (blank($name)) {
            return null;
        }

        $slug = Str::slug($name);

        return Brand::query()->firstOrCreate(
            ['slug' => $slug ?: 'brand-'.sha1(Str::lower($name))],
            ['name' => trim($name), 'is_active' => true],
        );
    }

    private function uniqueSlug(ProductDraft $draft): string
    {
        $base = Str::slug($draft->title) ?: "product-{$draft->id}";

        return Product::query()->where('slug', $base)->exists() ? "{$base}-{$draft->id}" : $base;
    }

    private function upsertVariant(Product $product, ProductDraft $draft): ProductVariant
    {
        $identifiers = collect(ProductCatalogIdentity::identifiers($draft->specifications ?? []));
        // Specification text is written by an AI, so one day the same machine
        // arrives as "32 GB" and the next as "32 ГБ" - hashed raw, those became
        // one product with two variants. Transliteration folds the alphabets
        // together and collapsing punctuation folds "32gb" in with them. It
        // deliberately stops there: turning "1 TB" into "1024 GB" would be a
        // guess about the world, not a normalization of how it was written.
        $normalize = fn (string $text): string => trim((string) preg_replace(
            '/[^a-z0-9]+/',
            '',
            Str::lower(Str::ascii($text)),
        ));
        $specifications = collect($draft->specifications ?? [])
            ->mapWithKeys(fn (mixed $item, mixed $key): array => [
                $normalize((string) (is_array($item) ? ($item['name'] ?? $key) : $key)) => $normalize((string) (is_array($item) ? ($item['value'] ?? '') : $item)),
            ])->sortKeys()->all();
        $fingerprint = sha1(json_encode([
            'color' => Str::lower((string) $draft->color),
            'sku' => $identifiers->get('sku'),
            'mpn' => $identifiers->get('mpn'),
            'gtin' => $identifiers->get('gtin') ?: $identifiers->get('ean') ?: $identifiers->get('upc'),
            'specifications' => $specifications,
            ...(($draft->condition ?? 'new') !== 'new' ? ['condition' => $draft->condition] : []),
        ], JSON_UNESCAPED_UNICODE));
        $targetVariantId = $draft->isManualUpload() ? data_get($draft->manualIntake(), 'target_variant_id') : null;
        $variant = $targetVariantId ? $product->variants()->findOrFail($targetVariantId) : (($identifiers->isNotEmpty()
            ? app(ProductCatalogIdentity::class)->variant($product, $draft->specifications ?? [], $draft->condition ?? 'new')
            : null) ?? ProductVariant::query()->firstOrNew([
                'product_id' => $product->id,
                'fingerprint' => $fingerprint,
            ]));

        $commercial = $draft->isManualUpload() ? ($draft->manualIntake()['commercial'] ?? []) : [];
        $quantityChanged = isset($commercial['quantity'])
            && (! $targetVariantId || (int) $commercial['quantity'] !== (int) $variant->quantity);

        $variant->fill([
            'name' => $targetVariantId ? $variant->name : ($draft->color ?: 'Базовая конфигурация'),
            'sku' => $identifiers->get('sku'),
            'mpn' => $identifiers->get('mpn'),
            'gtin' => $identifiers->get('gtin') ?: $identifiers->get('ean') ?: $identifiers->get('upc'),
            'color' => $draft->color,
            'condition' => $draft->condition ?? 'new',
            'translations' => CatalogLocales::textTranslations($draft->translations),
            'currency' => $commercial['currency'] ?? ($variant->currency ?: 'CZK'),
            'stock_status' => $quantityChanged
                ? ((int) $commercial['quantity'] > 0 ? 'in_stock' : 'out_of_stock')
                : ($variant->stock_status ?: 'unknown'),
            'is_default' => $variant->exists ? $variant->is_default : ! $product->variants()->exists(),
            'is_active' => $targetVariantId ? $variant->is_active : true,
            ...(array_key_exists('price_amount', $commercial) ? ['price' => $commercial['price_amount']] : []),
            ...(isset($commercial['quantity']) ? ['quantity' => $commercial['quantity']] : []),
            ...(array_key_exists('warranty_months', $commercial) ? ['warranty_months' => $commercial['warranty_months']] : []),
        ])->save();

        return $variant;
    }

    private function assertManualIntakeReady(ProductDraft $draft): void
    {
        foreach (['draft-upload', 'draft-fields', 'draft-enrich', 'draft-photo-actions'] as $operation) {
            throw_if(Cache::has("{$operation}:{$draft->id}:queued"), \RuntimeException::class,
                'Карточка ещё обрабатывается. Дождитесь актуального результата перед сохранением.');
        }
        throw_unless(($draft->manualIntake()['status'] ?? '') === 'ready'
            && filled($draft->title) && in_array($draft->condition, ProductCondition::VALUES, true)
            && Category::query()->where('slug', $draft->category)->where('is_active', true)->exists(),
            \RuntimeException::class, 'В ручной карточке не хватает обязательных данных или есть противоречия. Сначала исправьте её.');
        foreach ($draft->media()->get() as $media) {
            $valid = filled($media->disk) && filled($media->path)
                && in_array($media->verification_status, ['manual', 'verified', 'source_verified'], true)
                && Storage::disk($media->disk)->exists($media->path);
            $size = $valid ? @getimagesize(Storage::disk($media->disk)->path($media->path)) : false;
            throw_unless($size !== false && $size[0] > 0 && $size[1] > 0,
                UnverifiedDraftMediaException::class, 'Файл ручной фотографии недоступен или повреждён. Загрузите его заново.');
        }
    }

    /**
     * Removing/changing an inherited value affects only the reviewed variant.
     * Keep untouched globals shared; materialize affected values for siblings first.
     *
     * @return array<int, string> Keys whose global values must remain untouched.
     */
    private function preserveOtherVariantAttributes(Product $product, ProductVariant $variant, array $specifications): array
    {
        $wanted = collect($specifications)->keyBy(fn ($spec): string => $spec['key'] ?? $this->canonicalAttributeKey($spec['name']));
        $kept = [];
        $selectedDefinitions = $variant->attributes()->pluck('attribute_definition_id')->all();
        foreach ($product->attributes()->whereNull('product_variant_id')->with('definition')->get() as $global) {
            $key = $global->definition->key;
            $spec = $wanted->get($key);
            if ($spec && in_array($global->attribute_definition_id, $selectedDefinitions, true)) {
                // An existing override already separates this variant from the shared value.
                continue;
            }
            if ($spec && trim((string) $spec['value']) === trim((string) $global->value)
                && trim((string) ($spec['unit'] ?? $global->unit)) === trim((string) $global->unit)
                && ! in_array($global->attribute_definition_id, $selectedDefinitions, true)) {
                $kept[] = $key;

                continue;
            }
            foreach ($product->variants()->whereKeyNot($variant->id)->get() as $sibling) {
                $sibling->attributes()->firstOrCreate(['attribute_definition_id' => $global->attribute_definition_id], [
                    ...$global->only(['value', 'normalized_value', 'numeric_value', 'boolean_value', 'unit', 'translations']),
                    'product_id' => null,
                ]);
            }
            $global->delete();
        }

        return $kept;
    }

    /**
     * @param  array<string, array<string, array{name: string, value: string}>>  $translations  locale => key => translated name/value
     */
    private function syncAttributes(Product $product, ProductVariant $variant, Category $category, array $specifications, array $translations = [], bool $variantScoped = false, array $unchangedGlobalKeys = []): void
    {
        foreach ($specifications as $key => $specification) {
            $label = is_array($specification)
                ? ($specification['name'] ?? $specification['label'] ?? (is_string($key) ? $key : 'Характеристика'))
                : (is_string($key) ? $key : 'Характеристика');
            $rawValue = is_array($specification) ? ($specification['value'] ?? null) : $specification;

            if (! is_scalar($rawValue) || blank((string) $rawValue)) {
                continue;
            }

            $suppliedKey = is_array($specification) ? ($specification['key'] ?? null) : null;
            $attributeKey = is_string($suppliedKey) && preg_match('/^[a-z0-9_-]+$/', $suppliedKey) === 1
                ? $suppliedKey
                : $this->canonicalAttributeKey((string) $label);
            if (in_array($attributeKey, $unchangedGlobalKeys, true)) {
                continue;
            }
            [$dataType, $numericValue, $unit] = $this->typedValue($attributeKey, (string) $rawValue);
            $isFilterable = in_array($attributeKey, self::FILTERABLE_ATTRIBUTE_KEYS, true);
            $definition = AttributeDefinition::query()->firstOrCreate(
                ['key' => $attributeKey],
                [
                    'label' => $this->canonicalLabel($attributeKey, (string) $label),
                    'data_type' => $dataType,
                    'default_unit' => $unit,
                    'is_filterable' => $isFilterable,
                    'is_variant' => $isFilterable,
                ],
            );
            if ($definition->wasRecentlyCreated && is_array($specification)) {
                $group = AiAttributeGroups::resolve($specification['group'] ?? null);
                if ($group !== null) {
                    $definition->update(['attribute_group_id' => $group->id]);
                }
            }
            $definition->categories()->syncWithoutDetaching([$category->id]);
            // A definition is shared by every product, and its label may have
            // been edited by hand: only names it does not have yet are added.
            // The card's own name is the English one.
            $missingNames = array_diff_key([
                CatalogLocales::CONTENT_SOURCE => trim((string) $label),
                ...array_filter(array_map(fn (array $items): ?string => $items[$attributeKey]['name'] ?? null, $translations)),
            ], $definition->translations ?? []);
            if ($missingNames !== []) {
                $definition->update(['translations' => [...($definition->translations ?? []), ...$missingNames]]);
            }
            $valueTranslations = array_filter(array_map(fn (array $items): ?string => $items[$attributeKey]['value'] ?? null, $translations));
            $belongsToVariant = $variantScoped || $isFilterable;
            $owner = $belongsToVariant ? $variant->attributes() : $product->attributes();
            $typedFields = ['numeric_value' => $numericValue, 'unit' => $unit];
            if ($variantScoped) {
                $existing = $owner->where('attribute_definition_id', $definition->id)->first();
                $suppliedUnit = is_array($specification) && array_key_exists('unit', $specification)
                    ? $specification['unit'] : ($existing?->unit ?? $definition->default_unit ?? $unit);
                if ($existing && trim((string) $existing->value) === trim((string) $rawValue)
                    && trim((string) $existing->unit) === trim((string) $suppliedUnit)
                    && ($existing->translations ?? []) == $valueTranslations) {
                    continue;
                }
                $normalized = app(ManualProductAttributes::class)->normalize([
                    'attribute_definition_id' => $definition->id, 'value' => $rawValue, 'unit' => $suppliedUnit,
                ]);
                $typedFields = array_intersect_key($normalized ?? [], array_flip(['normalized_value', 'numeric_value', 'boolean_value', 'unit']));
            }
            $owner->updateOrCreate(
                ['attribute_definition_id' => $definition->id],
                [
                    'product_id' => $belongsToVariant ? null : $product->id,
                    'product_variant_id' => $belongsToVariant ? $variant->id : null,
                    'value' => trim((string) $rawValue),
                    'normalized_value' => Str::lower(trim((string) $rawValue)),
                    ...$typedFields,
                    // Replaced, not merged: a translation of an older value
                    // must not survive next to a new English value.
                    'translations' => $valueTranslations === [] ? null : $valueTranslations,
                ],
            );
        }
    }

    private function syncSources(Product $product, ProductVariant $variant, ProductDraft $draft): void
    {
        $sources = collect($draft->sources ?? [])->concat(
            collect($draft->specifications ?? [])
                ->filter(fn ($spec) => is_array($spec) && filled($spec['source_url'] ?? null))
                ->map(fn ($spec) => ['url' => $spec['source_url'], 'type' => 'web']),
        )->unique('url');

        foreach ($sources as $source) {
            $url = $source['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $product->sources()->firstOrCreate(
                ['url' => $url, 'product_variant_id' => $variant->id],
                [
                    'product_draft_id' => $draft->id,
                    'title' => $source['title'] ?? (parse_url($url, PHP_URL_HOST) ?: 'Источник'),
                    'domain' => parse_url($url, PHP_URL_HOST),
                    'source_type' => in_array(($source['type'] ?? null), ProductSourceTypes::VALUES, true) ? $source['type'] : 'web',
                    'retrieved_at' => now(),
                    'confidence' => $draft->confidence,
                ],
            );
        }
    }

    private function guessProductType(ProductDraft $draft): string
    {
        $haystack = Str::lower($draft->title.' '.$draft->model.' '.json_encode($draft->specifications));
        // Component keywords (cpu, ram, ssd…) appear in laptop/desktop specs too,
        // so they are matched against the identity only, not the specifications.
        $identity = Str::lower($draft->title.' '.$draft->model);

        return match (true) {
            Str::contains($haystack, [
                'laptop', 'notebook', 'ноутбук', 'macbook', 'legion', 'ideapad', 'thinkpad',
                'vivobook', 'zenbook', 'pavilion', 'aspire', 'inspiron', 'surface laptop',
            ]) => 'laptop',
            Str::contains($haystack, ['desktop', 'workstation', 'компьютер', 'готовый пк', 'mini pc']) => 'desktop',
            Str::contains($identity, [
                'gpu', 'cpu', 'видеокарт', 'процессор', 'motherboard', 'материн', 'ram', 'оператив',
                'ssd', 'hdd', 'накопител', 'power supply', 'блок питания', 'cooler', 'кулер',
                'корпус', 'case', 'monitor', 'монитор', 'keyboard', 'клавиатур', 'mouse', 'мыш',
            ]) => 'component',
            default => 'other',
        };
    }

    private function canonicalAttributeKey(string $label): string
    {
        $label = Str::lower($label);

        return match (true) {
            Str::contains($label, ['cpu', 'processor', 'процессор']) => 'cpu',
            Str::contains($label, ['gpu', 'graphics', 'video', 'видео']) => 'gpu',
            Str::contains($label, ['ram', 'memory', 'оператив']) => 'ram',
            Str::contains($label, ['storage', 'ssd', 'накопител']) => 'storage',
            Str::contains($label, ['refresh', 'частот']) => 'refresh_rate',
            Str::contains($label, ['screen size', 'diagonal', 'диагонал']) => 'screen_size',
            Str::contains($label, ['display', 'screen', 'экран']) => 'display',
            in_array($label, self::FILTERABLE_ATTRIBUTE_KEYS, true) => $label,
            default => Str::slug($label, '_') ?: 'attribute_'.substr(sha1($label), 0, 12),
        };
    }

    private function canonicalLabel(string $key, string $fallback): string
    {
        return match ($key) {
            'cpu' => 'Процессор',
            'gpu' => 'Видеокарта',
            'ram' => 'Оперативная память',
            'storage' => 'Накопитель',
            'display' => 'Дисплей',
            'screen_size' => 'Диагональ',
            'refresh_rate' => 'Частота экрана',
            default => trim($fallback),
        };
    }

    private function typedValue(string $key, string $value): array
    {
        $numberKeys = ['ram', 'storage', 'screen_size', 'refresh_rate'];

        if (! in_array($key, $numberKeys, true) || preg_match('/(\d+(?:[.,]\d+)?)/', $value, $matches) !== 1) {
            return ['text', null, null];
        }

        $number = (float) str_replace(',', '.', $matches[1]);
        $lower = Str::lower($value);
        $unit = match ($key) {
            'ram', 'storage' => 'GB',
            'screen_size' => 'inch',
            'refresh_rate' => 'Hz',
        };

        if ($key === 'storage' && Str::contains($lower, ['tb', 'тб'])) {
            $number *= 1024;
        }

        return ['number', $number, $unit];
    }
}
