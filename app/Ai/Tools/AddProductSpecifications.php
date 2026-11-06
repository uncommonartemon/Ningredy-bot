<?php

namespace App\Ai\Tools;

use App\Ai\Agents\ProductTextEvidenceAgent;
use App\Ai\Tools\Concerns\RecordsOperations;
use App\Jobs\TranslateProductText;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Ai\StreamingProductResearch;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Products\ProductSourcePriority;
use App\Services\Products\ProductSourceTypes;
use App\Services\Products\ProductTextEvidenceContract;
use App\Services\ProductSearch\Catalogs\ReferenceSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Specifications added to a product already in the catalog. Live 2026-11-02: a
 * colleague asked for an SSD's speeds; research found them on a benchmark page,
 * the product already existed, nothing could write them in, and the bot said the
 * page had no numbers. Only what the operator wrote or a page quotes is written.
 */
class AddProductSpecifications implements Tool
{
    use RecordsOperations;

    /** Observations about another configuration or the whole family are not this product's facts. */
    private const array APPLICABLE_SCOPES = ['exact_configuration', 'shared_chassis'];

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Add specifications to a product ALREADY in the catalog (for example SSD read/write speed). '
            .'Three ways: values the operator wrote in this message, typed or read from their screenshot (facts_json with from_operator=true); '
            .'facts already found with a page and quotation, such as found_specifications of ResearchProduct (facts_json with source_url and source_excerpt); '
            .'or, with no facts_json, a web search for "wanted" by the product\'s own codes on the reference sites for its kind. '
            .'Writes only a value the operator wrote or a page quotes. A value the product already has is kept unless replace_existing=true because the operator asked to correct it. '
            .'Returns what was written with its source, what was not, and links to sites only a person can open.';
    }

    public function handle(Request $request): Stringable|string
    {
        $product = Product::query()->with(['brand', 'category', 'variants'])->find($request->integer('product_id'));
        if ($product === null) {
            return $this->json(['ok' => false, 'error' => 'Product not found.']);
        }
        $wanted = trim((string) $request->string('wanted'));
        // The configuration the operator named, when the product has several: its own
        // codes lead the search and the values become its own (audit 2026-11-04, C5).
        $configuration = null;
        if ($request->integer('product_variant_id') > 0) {
            $configuration = $product->variants->firstWhere('id', $request->integer('product_variant_id'));
            if ($configuration === null) {
                return $this->json(['ok' => false, 'product_id' => $product->id, 'error' => 'product_variant_id is not a configuration of this product.',
                    'configurations' => $product->variants->sortBy('id')->map(fn ($variant): array => ['id' => $variant->id, 'name' => $variant->name, 'sku' => $variant->sku])->values()->all()]);
            }
        }
        $codes = $this->codes($product, $configuration);
        $existing = $this->existing($product, $configuration);
        $referenceSites = app(ReferenceSites::class);
        $personLinks = $referenceSites->personLinks($codes, $product->model, $product->brand?->name);
        try {
            $supplied = $this->suppliedFacts((string) ($request['facts_json'] ?? ''));
        } catch (JsonException $exception) {
            return $this->json(['ok' => false, 'error' => 'facts_json must be a JSON array of facts: '.$exception->getMessage()]);
        }
        $search = $supplied === [] ? $this->search($product, $wanted, $codes, $existing) : null;
        if ($search !== null && ! $search['ok']) {
            return $this->json([...$search, 'product_id' => $product->id, 'person_links' => $personLinks,
                'message' => 'Поиск не выполнен: '.$search['reason'].' Скажи это оператору этими словами. Ссылки person_links подходят, если их «for» совпадает с видом товара: оператор может открыть сам и прислать скриншот или текст.']);
        }

        $added = [];
        $kept = [];
        $rejected = [];
        foreach ($supplied !== [] ? $supplied : $search['facts'] as $fact) {
            $reason = $this->rejection($fact);
            if ($reason !== null) {
                $rejected[] = ['name' => $fact['name'], 'value' => $fact['value'], 'reason' => $reason];

                continue;
            }
            $current = $existing[$fact['key']] ?? null;
            if ($current !== null && (! $request->boolean('replace_existing') || self::sameValue($current['value'], $fact['value']))) {
                $kept[] = ['name' => $current['name'], 'current' => $current['value'], 'found' => $fact['value']];

                continue;
            }
            $added[$fact['key']] = $fact;
        }
        $added = array_values($added);

        $result = [];
        if ($added !== []) {
            try {
                $result = $this->recordOperation($this->update, class_basename(self::class), 'add_product_specifications',
                    ['product_id' => $product->id, 'product_variant_id' => $configuration?->id, 'facts' => $added], function () use ($product, $configuration, $added): array {
                        app(ProductDraftWorkflow::class)->addSpecifications($product, array_map(
                            fn (array $fact): array => ['key' => $fact['key'], 'name' => $fact['name'], 'value' => $fact['value']], $added), $configuration);
                        foreach (collect($added)->whereNotNull('source_url')->unique('source_url') as $fact) {
                            $product->sources()->firstOrCreate(['url' => $fact['source_url'], 'product_variant_id' => $configuration?->id], [
                                'title' => (string) (parse_url($fact['source_url'], PHP_URL_HOST) ?: 'Источник'),
                                'domain' => parse_url($fact['source_url'], PHP_URL_HOST) ?: null,
                                'source_type' => in_array($fact['source_type'] ?? null, ProductSourceTypes::VALUES, true) ? $fact['source_type'] : 'web',
                                'retrieved_at' => now(),
                            ]);
                        }

                        return ['product_id' => $product->id, 'added' => array_column($added, 'key')];
                    }, Product::class, $product->id);
            } catch (Throwable $exception) {
                report($exception);

                return $this->json(['ok' => false, 'product_id' => $product->id, 'error' => mb_substr($exception->getMessage(), 0, 800),
                    'message' => 'Характеристики не записаны из-за ошибки. Скажи это оператору прямо.']);
            }
            // The card's other languages are made from the English values, off this request.
            TranslateProductText::dispatch($product->id);
            $referenceSites->noteFacts(array_values(array_filter(array_column($added, 'source_url'))), $this->update->id);
        }

        return $this->json([
            'ok' => true,
            'product_id' => $product->id,
            'configuration' => $configuration === null ? null : ['id' => $configuration->id, 'name' => $configuration->name, 'sku' => $configuration->sku],
            'operation_id' => $result['operation_id'] ?? null,
            'added' => array_map(fn (array $fact): array => ['name' => $fact['name'], 'value' => $fact['value'],
                'source' => $fact['from_operator'] ? 'operator' : $fact['source_url']], $added),
            'already_in_product' => $kept,
            'rejected' => $rejected,
            'search_summary' => $search['summary'] ?? null,
            'web_search_calls' => $search['web_search_calls'] ?? 0,
            'person_links' => $personLinks,
            'message' => 'Перечисли, что записано в товар (название: значение) и откуда: домен страницы или «со слов оператора». '
                .'already_in_product не менялось: если оператор просил исправить, вызови снова с replace_existing=true. '
                .'rejected не записано: назови коротко причину. Если записано не всё, что просили, скажи прямо, чего нет (search_summary), '
                .'и предложи ссылки person_links, чьё «for» подходит к этому товару: оператор откроет сам и пришлёт скриншот или текст. '
                .'Не утверждай о страницах того, чего нет в этом результате.',
        ]);
    }

    /**
     * Why a fact is not written, or null: the operator's value must be in their own
     * message, a page's value in its quotation, from an allowed page about this product.
     */
    private function rejection(array $fact): ?string
    {
        if ($fact['from_operator']) {
            return self::quotes($fact['value'], (string) $this->update->text) ? null
                : 'этого значения нет в сообщении оператора';
        }
        $url = (string) ($fact['source_url'] ?? '');
        if (! preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'нет адреса страницы';
        }
        if (app(ProductSourcePriority::class)->isBlockedUrl($url)) {
            return 'сайт заблокирован';
        }
        if (isset($fact['scope']) && ! in_array($fact['scope'], self::APPLICABLE_SCOPES, true)) {
            return 'страница говорит о другой конфигурации или о всём семействе';
        }

        return self::quotes($fact['value'], (string) ($fact['source_excerpt'] ?? '')) ? null
            : 'значения нет в цитате со страницы';
    }

    /**
     * The text says this value: every number of it stands in the text as a whole
     * number (2,200 MB/s and 2200 MB/s are one number; 16 is not part of 160) with
     * the same unit after it ("16 GB" is not "16 cores"), or, with no number, every
     * word of it stands there as a whole word.
     */
    public static function quotes(string $value, string $text): bool
    {
        $valueTokens = self::tokens($value);
        $textTokens = self::tokens($text);
        if ($valueTokens === [] || $textTokens === []) {
            return false;
        }
        $numeric = fn (string $token): bool => preg_match('/^\d+(?:\.\d+)?$/', $token) === 1;
        $numbers = array_keys(array_filter($valueTokens, $numeric));
        if ($numbers === []) {
            $words = array_filter($valueTokens, fn (string $token): bool => mb_strlen($token) >= 3) ?: $valueTokens;
            $lower = array_map('mb_strtolower', $textTokens);

            return collect($words)->every(fn (string $word): bool => in_array(mb_strtolower($word), $lower, true));
        }
        foreach ($numbers as $index) {
            $unit = isset($valueTokens[$index + 1]) && ! $numeric($valueTokens[$index + 1])
                ? mb_strtolower(mb_substr($valueTokens[$index + 1], 0, 2)) : null;
            $found = false;
            foreach (array_keys($textTokens, $valueTokens[$index], true) as $position) {
                $next = isset($textTokens[$position + 1]) ? mb_strtolower(mb_substr($textTokens[$position + 1], 0, 2)) : null;
                if ($unit === null || $next === $unit) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Words and numbers of a text: thousands groups joined (2,138 / 2 138 → 2138), a
     * decimal comma made a dot, a whole decimal shortened (4.0 → 4).
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        $text = (string) preg_replace('/(\d)[ \x{00A0}\x{202F}\'](\d{3})(?!\d)/u', '$1$2', $text);
        $text = (string) preg_replace('/(\d),(\d{3})(?!\d)/u', '$1$2', $text);
        $text = (string) preg_replace('/(\d),(\d)/u', '$1.$2', $text);

        return array_values(array_map(
            fn (string $token): string => preg_match('/^\d+\.\d+$/', $token) === 1 ? rtrim(rtrim($token, '0'), '.') : $token,
            preg_split('/[^\p{L}\p{N}.]+|(?<!\d)\.|\.(?!\d)/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ));
    }

    private static function sameValue(string $left, string $right): bool
    {
        $plain = fn (string $subject): string => (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($subject)));

        return $plain($left) === $plain($right);
    }

    /**
     * @return list<array{key: string, name: string, value: string, source_url: ?string, source_excerpt: ?string, source_type: ?string, from_operator: bool}>
     *
     * @throws JsonException
     */
    private function suppliedFacts(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $facts = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($facts) || ! array_is_list($facts)) {
            throw new JsonException('not a list');
        }

        return collect($facts)->filter(fn (mixed $fact): bool => is_array($fact) && filled($fact['name'] ?? null) && filled($fact['value'] ?? null))
            ->take(50)->map(fn (array $fact): array => self::fact($fact, (bool) ($fact['from_operator'] ?? false)))->values()->all();
    }

    private static function fact(array $fact, bool $fromOperator): array
    {
        $name = mb_substr(trim((string) $fact['name']), 0, 255);
        $key = (string) ($fact['key'] ?? '');

        return [
            'key' => preg_match('/^[a-z0-9_]{1,100}$/', $key) === 1 ? $key : (Str::slug($name, '_') ?: 'attribute_'.substr(sha1($name), 0, 12)),
            'name' => $name,
            'value' => mb_substr(trim((string) $fact['value']), 0, 2000),
            'source_url' => filled($fact['source_url'] ?? null) ? trim((string) $fact['source_url']) : null,
            'source_excerpt' => filled($fact['source_excerpt'] ?? null) ? (string) $fact['source_excerpt'] : null,
            'source_type' => $fact['source_type'] ?? null,
            'from_operator' => $fromOperator,
            ...(isset($fact['scope']) ? ['scope' => (string) $fact['scope']] : []),
        ];
    }

    /**
     * The facts the operator asked for, researched on the web for this exact product.
     *
     * @param  list<string>  $codes
     * @param  array<string, array{name: string, value: string}>  $existing
     * @return array{ok: bool, facts?: list<array<string, mixed>>, summary?: string, web_search_calls?: int, status?: string, reason?: string}
     */
    private function search(Product $product, string $wanted, array $codes, array $existing): array
    {
        $updateId = $this->update->id;
        if ($wanted === '') {
            return ['ok' => false, 'status' => 'nothing_requested', 'reason' => 'не сказано, какие характеристики искать.'];
        }
        if (app(ProductSearchCancellation::class)->isCancelled($updateId)) {
            return ['ok' => false, 'status' => 'cancelled', 'reason' => 'запрос отменён.'];
        }
        if (app(ProductSearchCostBudget::class)->exceeded($updateId)) {
            return ['ok' => false, 'status' => 'cost_budget_exhausted', 'reason' => 'денежный лимит этого запроса исчерпан.'];
        }
        $time = app(ProductSearchTimeBudget::class);
        if (! $time->canStart($updateId, 20)) {
            return ['ok' => false, 'status' => 'time_budget_exhausted', 'reason' => 'лимит времени этого запроса исчерпан.'];
        }
        $settings = app(AiSettings::class);
        $provider = $settings->providerFor('product_specification_reconciliation');
        $model = $settings->modelFor('product_specification_reconciliation');
        $prompt = json_encode([
            'task' => 'This product is already in the catalog. Research only the facts the operator asked for, about this exact product '
                .'(its codes below), and quote each from the page you read. Write name and value in English; keep numbers and units exactly as the page '
                .'publishes them. Facts it already has are listed so you do not repeat them.',
            'original_operator_request' => $wanted,
            'selected_product' => ['title' => $product->title, 'brand' => $product->brand?->name, 'model' => $product->model,
                'codes' => $codes, 'category' => $product->category?->name],
            'existing_specifications' => array_map(fn (array $item): string => $item['name'].': '.$item['value'], $existing),
            'missing_or_conflicting_facts' => $wanted,
            'reference_sites' => app(ReferenceSites::class)->hints($codes, $product->model, $product->brand?->name),
            'catalog_keys' => AttributeDefinition::query()->has('values')->withCount('values')->orderByDesc('values_count')->limit(80)->pluck('key')->all(),
            'excluded_domains' => app(ProductSourcePriority::class)->blockedDomains(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $run = AiRun::query()->create(['telegram_update_id' => $updateId, 'provider' => $provider, 'model' => $model,
            'status' => 'running', 'prompt' => $prompt, 'started_at' => now(), 'activity' => ['stage' => 'add_specifications']]);
        $searchItems = [];
        try {
            $response = app(StreamingProductResearch::class)->prompt(
                ProductTextEvidenceAgent::make(), $prompt, $provider, $model,
                $time->timeoutFor($updateId, $settings->productResearchTimeoutSeconds()),
                $settings->productResearchIdleTimeoutSeconds(),
                function ($event) use (&$searchItems, $run): void {
                    if ($event instanceof ProviderToolEvent && $event->type === 'web_search_call' && $event->itemId !== '') {
                        $searchItems[$event->itemId] = true;
                        $run->update(['usage' => ['web_search_calls' => count($searchItems)], 'last_activity_at' => now()]);
                    }
                },
            );
            $data = $response->toArray();
            if (array_key_exists('facts', $data)) {
                $data['facts'] = ProductTextEvidenceContract::normalizeGroups($data['facts']);
            }
            $data = Validator::make($data, [
                ...ProductTextEvidenceContract::rules('facts', includeGroup: true),
                'facts' => ['present', 'array', 'max:100'],
                'summary' => ['required', 'string', 'max:1500'],
            ])->validate();
            $run->update(['invocation_id' => $response->invocationId, 'status' => 'completed', 'response' => $data, 'completed_at' => now(),
                'usage' => [...$response->usage->toArray(), 'web_search_calls' => count($searchItems)]]);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);
            report($exception);

            return ['ok' => false, 'status' => 'search_failed', 'reason' => app(AiErrorPresenter::class)->present($exception)['message']];
        }

        return ['ok' => true, 'summary' => $data['summary'], 'web_search_calls' => count($searchItems),
            'facts' => array_map(fn (array $fact): array => self::fact($fact, false), $data['facts'])];
    }

    /**
     * The product's own codes: the named configuration's SKU, MPN and GTIN (else every configuration's) and its model.
     *
     * @return list<string>
     */
    private function codes(Product $product, ?ProductVariant $configuration = null): array
    {
        return ($configuration === null ? $product->variants : collect([$configuration]))
            ->flatMap(fn ($variant): array => [$variant->sku, $variant->mpn, $variant->gtin])
            ->push($product->model)->filter(fn (mixed $code): bool => is_string($code) && trim($code) !== '')
            ->map(fn (string $code): string => trim($code))->unique()->values()->all();
    }

    /**
     * What the product already says: its shared values and those of the named
     * configuration (else of its default one) - another configuration's value
     * never blocks this one's.
     *
     * @return array<string, array{name: string, value: string}> key => name and value
     */
    private function existing(Product $product, ?ProductVariant $configuration = null): array
    {
        $variant = $configuration ?? $product->variants->firstWhere('is_default', true) ?? $product->variants->sortBy('id')->first();

        return AttributeValue::query()->with('definition')
            ->where(fn ($query) => $query->where('product_id', $product->id)->orWhere('product_variant_id', $variant?->id ?? 0))
            ->get()->filter(fn (AttributeValue $value): bool => $value->definition !== null)
            ->mapWithKeys(fn (AttributeValue $value): array => [$value->definition->key => [
                'name' => (string) ($value->definition->translations['en'] ?? $value->definition->label), 'value' => (string) $value->value]])
            ->all();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required(),
            'wanted' => $schema->string()->max(1000)->required()->description('What to add, in the operator\'s words: "скорость чтения и записи".'),
            'facts_json' => $schema->string()->max(50000)->description('Optional JSON array of facts to write: [{"name":"Sequential read speed","value":"2200 MB/s","key":"sequential_read_speed","source_url":"https://…","source_excerpt":"…"}] '
                .'or, for values the operator wrote, [{"name":"…","value":"…","from_operator":true}]. Name and value in English; numbers and units as given. Omit to search the web.'),
            'replace_existing' => $schema->boolean()->description('true only when the operator asked to correct a value the product already has.'),
            'product_variant_id' => $schema->integer()->description('The configuration (variant) the operator named when the product has several, for example "для версии 1 ТБ": its id from the catalog tools. Omit for the product as a whole.'),
        ];
    }
}
