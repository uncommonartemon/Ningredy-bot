<?php

namespace App\Services\DatabaseOperator;

use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\ProductMedia;
use App\Models\ProductSource;
use App\Models\ProductSourceAttempt;
use App\Models\ProductSourceDomain;
use App\Models\ProductSourcePageEvidence;
use App\Models\ProductSourcePageRule;
use App\Models\ProductSourceStat;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\Products\CatalogEditRevision;
use App\Services\Products\CatalogLocales;
use App\Services\Telegram\DraftAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Typed business-data operations; never SQL, schema changes, secrets or workflow proofs. */
class DatabaseOperatorService
{
    private const READ_ONLY_REASON = 'История и доказательства доступны для чтения. Изменяйте их через специализированный проверяемый процесс, чтобы сохранить аудит, бюджет и подтверждения.';

    private const SYSTEM_REASON = 'Системная таблица: ключи, права, настройки, очередь и идемпотентность недоступны для произвольного изменения через чат.';

    private const FILTERS = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'contains', 'starts_with', 'is_null', 'not_null'];

    /** @return array<string, array{model: class-string<Model>, writable: array, create?: bool, delete?: bool, read?: array, reason?: string}> */
    private function registry(): array
    {
        $text = 'nullable|string|max:255';
        $bool = 'boolean';
        $order = 'integer|min:0|max:2147483647';
        $translations = 'nullable|array';
        $fk = fn (string $table, bool $nullable = true): array => [$nullable ? 'nullable' : 'required', 'integer', 'min:1', Rule::exists($table, 'id')];
        $catalog = [
            'products' => ['model' => Product::class, 'writable' => [
                'title' => 'required|string|max:255', 'model' => $text, 'description' => 'nullable|string|max:200000',
                'category_id' => $fk('categories', false), 'brand_id' => $fk('brands'), 'slug' => 'string|max:255',
                'product_type' => 'in:laptop,desktop,component,other', 'status' => 'in:draft,published,archived',
                'is_active' => $bool, 'is_featured' => $bool, 'sort_order' => $order, 'translations' => $translations,
            ]],
            'product_variants' => ['model' => ProductVariant::class, 'writable' => [
                'product_id' => $fk('products', false), 'name' => $text, 'sku' => $text, 'mpn' => $text, 'gtin' => $text,
                'color' => $text, 'condition' => 'in:new,used,refurbished,open_box',
                'price' => 'nullable|numeric|min:0|max:9999999999.99', 'compare_at_price' => 'nullable|numeric|min:0|max:9999999999.99',
                'currency' => 'string|regex:/^[A-Z]{3}$/', 'stock_status' => 'in:unknown,in_stock,out_of_stock,preorder',
                'quantity' => $order, 'warranty_months' => 'nullable|integer|min:0|max:65535',
                'is_default' => $bool, 'is_active' => $bool, 'translations' => $translations,
            ]],
            'attribute_groups' => ['model' => AttributeGroup::class, 'writable' => [
                'name' => 'required|string|max:255', 'translations' => $translations, 'sort_order' => $order, 'highlight_in_filters' => $bool,
            ]],
            'attribute_definitions' => ['model' => AttributeDefinition::class, 'writable' => [
                'key' => 'string|regex:/^[a-z0-9][a-z0-9_-]*$/|max:255', 'label' => 'required|string|max:255',
                'data_type' => 'in:text,number,boolean', 'default_unit' => 'nullable|string|max:24',
                'is_filterable' => $bool, 'is_variant' => $bool, 'sort_order' => $order,
                'translations' => $translations, 'attribute_group_id' => $fk('attribute_groups'),
            ]],
            'attribute_values' => ['model' => AttributeValue::class, 'writable' => [
                'attribute_definition_id' => $fk('attribute_definitions', false), 'product_id' => $fk('products'),
                'product_variant_id' => $fk('product_variants'), 'value' => 'required|string|max:200000',
                'numeric_value' => 'nullable|numeric', 'boolean_value' => 'nullable|boolean',
                'unit' => 'nullable|string|max:24', 'translations' => $translations,
            ]],
            'categories' => ['model' => Category::class, 'writable' => [
                'name' => 'required|string|max:255', 'slug' => 'string|max:255', 'parent_id' => $fk('categories'),
                'product_type_affinity' => 'nullable|in:laptop,desktop,component,other', 'icon' => $text,
                'sort_order' => $order, 'is_active' => $bool, 'gallery_search_strategy' => 'in:auto,vision_first,playwright_first',
                'minimum_verified_images' => 'integer|min:1|max:10', 'minimum_image_width' => 'nullable|integer|min:100|max:4000',
                'minimum_image_height' => 'nullable|integer|min:0|max:4000', 'product_search_hint' => 'nullable|string|max:10000',
            ]],
            'category_translations' => ['model' => CategoryTranslation::class, 'writable' => [
                'category_id' => $fk('categories', false), 'locale' => 'in:en,cs,uk', 'name' => 'required|string|max:255',
            ]],
            'attribute_definition_category' => ['model' => AttributeCategoryLink::class, 'writable' => [
                'attribute_definition_id' => $fk('attribute_definitions', false), 'category_id' => $fk('categories', false),
                'is_required' => $bool, 'is_default' => $bool, 'sort_order' => $order,
            ]],
            'brands' => ['model' => Brand::class, 'writable' => [
                'name' => 'required|string|max:255', 'slug' => 'string|max:255', 'country' => $text,
                'website_url' => 'nullable|url:http,https|max:2048', 'is_active' => $bool,
            ]],
            'product_sources' => ['model' => ProductSource::class, 'writable' => [
                'product_id' => $fk('products', false), 'product_variant_id' => $fk('product_variants'),
                'title' => 'required|string|max:500', 'url' => 'required|url:http,https|max:8192',
                'source_type' => 'string|max:32',
            ]],
            'product_media' => ['model' => ProductMedia::class, 'create' => false, 'writable' => [
                'product_variant_id' => $fk('product_variants'), 'alt' => $text, 'role' => 'string|max:32',
                'sort_order' => $order, 'is_primary' => $bool,
            ]],
            'product_drafts' => ['model' => ProductDraft::class, 'writable' => [
                'title' => 'required|string|max:500', 'brand' => $text, 'model' => $text, 'color' => $text,
                'condition' => 'in:new,used,refurbished,open_box', 'description' => 'nullable|string|max:200000',
                'category' => 'nullable|string|max:255', 'product_type' => 'in:laptop,desktop,component,other',
                'specifications' => 'array', 'translations' => $translations, 'research_notes' => 'nullable|string|max:200000',
            ]],
            'product_draft_media' => ['model' => ProductDraftMedia::class, 'create' => false, 'writable' => [
                'role' => 'string|max:32', 'sort_order' => $order, 'is_primary' => $bool,
            ]],
            'product_source_domains' => ['model' => ProductSourceDomain::class, 'writable' => [
                'domain' => 'required|string|max:255', 'agent_hint' => 'nullable|string|max:20000',
            ]],
        ];
        foreach ([
            'product_gallery_recipes' => ProductGalleryRecipe::class,
            'product_gallery_recipe_versions' => ProductGalleryRecipeVersion::class,
            'product_source_page_rules' => ProductSourcePageRule::class,
            'product_source_stats' => ProductSourceStat::class,
            'product_source_attempts' => ProductSourceAttempt::class,
            'product_source_page_evidence' => ProductSourcePageEvidence::class,
        ] as $table => $model) {
            $catalog[$table] = ['model' => $model, 'writable' => [], 'reason' => self::READ_ONLY_REASON];
        }
        $catalog['ai_runs'] = ['model' => AiRun::class, 'writable' => [], 'reason' => self::READ_ONLY_REASON,
            'read' => ['id', 'telegram_update_id', 'provider', 'model', 'status', 'usage', 'error', 'started_at', 'completed_at', 'created_at', 'updated_at']];
        $catalog['ai_operations'] = ['model' => AiOperation::class, 'writable' => [], 'reason' => self::READ_ONLY_REASON,
            'read' => ['id', 'telegram_update_id', 'telegram_user_id', 'tool', 'action', 'target_type', 'target_id', 'status', 'error', 'executed_at', 'created_at', 'updated_at']];
        $catalog['telegram_updates'] = ['model' => TelegramUpdate::class, 'writable' => [], 'reason' => self::READ_ONLY_REASON,
            'read' => ['id', 'update_id', 'telegram_user_id', 'chat_id', 'message_id', 'status', 'error', 'processed_at', 'cancel_requested_at', 'created_at', 'updated_at']];

        return $catalog;
    }

    public function describe(): array
    {
        $tables = [];
        $registry = $this->registry();
        foreach (Schema::getTables() as $metadata) {
            $name = $metadata['name'];
            $entry = $registry[$name] ?? null;
            $tables[$name] = $entry ? [
                'readable' => true, 'actions' => $entry['writable'] === [] ? ['query'] : array_values(array_filter([
                    'query', ($entry['create'] ?? true) ? 'create' : null, 'update', ($entry['delete'] ?? true) ? 'delete' : null,
                ])),
                'columns' => $this->columns($name, $entry), 'writable_columns' => array_keys($entry['writable']),
                'column_details' => $this->columnDetails($name, $entry),
                'validation' => array_map(fn ($rules): array => array_map(fn ($rule): string => (string) $rule, is_array($rules) ? $rules : explode('|', $rules)), $entry['writable']),
                'reason' => $entry['reason'] ?? 'Проверки связей, переводов и подтверждений применяются сервером. ID, даты и доказательства не редактируются.',
                'selection' => $name === 'attribute_definition_category' ? 'Composite ids: attribute_definition_id:category_id; or filters/all.' : 'ids, id_from/id_to, filters or all:true.',
            ] : ['readable' => false, 'actions' => [], 'columns' => [], 'writable_columns' => [], 'reason' => self::SYSTEM_REASON];
        }

        return ['tables' => $tables, 'query_limit' => 200, 'all_selection_is_not_limited' => true,
            'references' => 'Create command ref="ram"; later values.attribute_group_id={"$ref":"ram.id"}.',
            'scope' => 'Drafts and audit rows belong to the current Telegram operator/chat. Catalog rows are shared. Deletes require confirmation.',
            'workflow' => 'New catalog products stay draft/inactive. Publish through draft approval. Media files, proof flags and recipe activation use specialized tools.'];
    }

    public function query(string $table, array $selection = [], array $columns = [], int $limit = 50, int $offset = 0, ?TelegramUpdate $context = null): array
    {
        $entry = $this->entry($table);
        $allowed = $this->columns($table, $entry);
        $columns = $columns === [] ? $allowed : $columns;
        $this->assert(array_diff($columns, $allowed) === [], 'columns', 'Недоступные колонки в запросе.');
        $query = $this->selected($table, $selection, $context, allowEmpty: true);
        $total = (clone $query)->count();
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $rows = $this->ordered($query, $table)->offset($offset)->limit($limit)->get($columns)
            ->map(fn (Model $row): array => $this->safeRow($row, $columns))->all();

        return ['table' => $table, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'rows' => $rows,
            'has_more' => $offset + count($rows) < $total, 'scope' => $this->scope($context),
            'scope_note' => $this->scopeNote($table, $context)];
    }

    /** Read a complete large business field in chunks, using exactly the same scope and allowlist. */
    public function readValue(string $table, int|string $id, string $column, int $offset = 0, int $length = 6000, ?TelegramUpdate $context = null): array
    {
        $entry = $this->entry($table);
        $this->assert(in_array($column, $this->columns($table, $entry), true), 'column', 'Колонка недоступна для чтения.');
        $row = $this->selected($table, ['ids' => [$id]], $context)->first();
        $this->assert($row !== null, 'id', 'Запись отсутствует или недоступна в текущем чате.');
        $value = $row->getAttribute($column);
        $json = ! is_string($value);
        $text = $json ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $value;
        $offset = max(0, $offset);
        $length = max(1, min(12000, $length));
        $chunk = mb_substr($text, $offset, $length);

        return ['table' => $table, 'id' => $id, 'column' => $column, 'text' => $chunk, 'encoding' => $json ? 'json' : 'text',
            'total_length' => mb_strlen($text), 'offset' => $offset, 'length' => mb_strlen($chunk),
            'has_more' => $offset + mb_strlen($chunk) < mb_strlen($text), 'scope' => $this->scope($context)];
    }

    public function prepare(array $commands, ?TelegramUpdate $context = null): array
    {
        $this->assert($commands !== [] && array_is_list($commands), 'commands', 'Передайте непустой список команд.');
        $plan = ['version' => 1, 'scope' => $this->scope($context), 'commands' => [], 'affected_count' => 0, 'requires_confirmation' => false];
        $refs = [];
        foreach ($commands as $index => $command) {
            $this->assert(is_array($command), 'commands', 'Команда должна быть объектом.');
            $this->assert(array_diff(array_keys($command), ['action', 'table', 'selection', 'values', 'ref']) === [], 'commands', 'Неизвестные поля команды.');
            $action = $command['action'] ?? '';
            $table = $command['table'] ?? '';
            $this->assert(is_string($table) && in_array($action, ['create', 'update', 'delete'], true), 'commands', 'Неизвестная таблица или действие.');
            $entry = $this->entry($table);
            $this->assert($entry['writable'] !== [], 'table', $entry['reason'] ?? self::SYSTEM_REASON);
            $this->assert(($entry[$action] ?? true) !== false, 'action', 'Создание фотографий выполняется инструментом загрузки/поиска с проверкой файла.');
            $values = $command['values'] ?? [];
            $this->assert(is_array($values) && ($values === [] || ! array_is_list($values)), 'values', 'values должен быть объектом полей.');
            $this->assert($action !== 'delete' || $values === [], 'values', 'Удаление не принимает значения полей.');
            $this->assert($action === 'delete' || $values !== [], 'values', 'Не указаны изменяемые поля.');
            $this->validateValues($table, $values, $action, $refs);
            $ref = $command['ref'] ?? null;
            if ($ref !== null) {
                $this->assert($action === 'create' && is_string($ref) && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $ref) && ! isset($refs[$ref]), 'ref', 'ref должен быть уникальным именем создаваемой записи.');
            }
            $rows = $action === 'create' ? [] : $this->ordered($this->selected($table, $command['selection'] ?? [], $context), $table)->cursor();
            if ($action === 'create') {
                $this->assert(($command['selection'] ?? []) === [], 'selection', 'Создание не принимает выборку.');
            }
            $ids = [];
            $fingerprints = [];
            $preview = [];
            $effects = [];
            foreach ($rows as $row) {
                $this->assertRowAllowed($row, $action, $values, $context);
                $ids[] = $row->getKey();
                $fingerprints[(string) $row->getKey()] = $this->fingerprint($row);
                if ($action === 'delete') {
                    foreach ($this->deletionEffects($row) as $effect) {
                        $key = json_encode([$effect['table'], $effect['action'], $effect['values']], JSON_THROW_ON_ERROR);
                        if (isset($effects[$key])) {
                            $effects[$key]['count'] += $effect['count'];
                        } else {
                            $effects[$key] = $effect;
                        }
                    }
                }
                if (count($preview) < 5) {
                    $preview[] = $this->safeRow($row, $this->columns($table, $entry));
                }
            }
            $count = $action === 'create' ? 1 : count($ids);
            $plan['commands'][] = ['action' => $action, 'table' => $table, 'ref' => $ref, 'ids' => $ids,
                'values' => $values, 'fingerprints' => $fingerprints, 'count' => $count,
                'preview' => $preview, 'effects' => array_values($effects),
                'scope_note' => $this->scopeNote($table, $context)];
            $plan['affected_count'] += $count;
            $plan['requires_confirmation'] = $plan['requires_confirmation'] || $action === 'delete';
            if ($ref !== null) {
                $refs[$ref] = $table;
            }
        }

        return $plan;
    }

    public function execute(array $plan, ?TelegramUpdate $context = null): array
    {
        $this->assert(($plan['version'] ?? null) === 1 && ($plan['scope'] ?? null) === $this->scope($context), 'plan', 'План принадлежит другому оператору/чату или имеет неподдерживаемую версию.');
        $this->assert(is_array($plan['commands'] ?? null) && $plan['commands'] !== [], 'plan', 'Пустой план.');

        return DB::transaction(function () use ($plan, $context): array {
            // Check every original snapshot before changing any row (commands may overlap).
            foreach ($plan['commands'] as $command) {
                $this->assert(in_array($command['action'] ?? '', ['create', 'update', 'delete'], true), 'plan', 'Неизвестное действие плана.');
                $entry = $this->entry($command['table']);
                $this->assert($entry['writable'] !== [] && ($entry[$command['action']] ?? true), 'plan', 'Действие больше не разрешено.');
                if ($command['action'] === 'create') {
                    continue;
                }
                foreach ($this->lockedBatches($command['table'], $command['ids'], $context) as $rows) {
                    foreach ($rows as $row) {
                        $expected = $command['fingerprints'][(string) $row->getKey()] ?? '';
                        $this->assert(is_string($expected) && hash_equals($expected, $this->fingerprint($row, true)), 'plan', 'Запись или её связанные данные изменились после подготовки. Запросите операцию заново.');
                    }
                }
            }
            $references = [];
            $results = [];
            foreach ($plan['commands'] as $command) {
                $table = $command['table'];
                $action = $command['action'];
                $values = $this->resolveReferences($command['values'], $references);
                $this->validateValues($table, $values, $action, []);
                $model = $this->entry($table)['model'];
                $batches = $action === 'create' ? [[new $model]] : $this->lockedBatches($table, $command['ids'], $context);
                $ids = [];
                foreach ($batches as $rows) {
                    foreach ($rows as $row) {
                        $this->assertRowAllowed($row, $action, $values, $context);
                        if ($action === 'delete') {
                            $ids[] = $row->getKey();
                            $this->delete($row);
                        } else {
                            $this->save($row, $values, $context);
                            $ids[] = $row->getKey();
                            if ($command['ref'] !== null) {
                                $references[$command['ref']] = ['id' => $row->getKey()];
                            }
                        }
                    }
                }
                $results[] = ['action' => $action, 'table' => $table, 'count' => count($ids), 'ids' => $ids];
            }

            return ['status' => 'completed', 'affected_count' => array_sum(array_column($results, 'count')), 'commands' => $results,
                'references' => $references, 'scope' => $this->scope($context)];
        });
    }

    private function entry(string $table): array
    {
        $entry = $this->registry()[$table] ?? null;
        $this->assert($entry !== null && Schema::hasTable($table), 'table', self::SYSTEM_REASON);

        return $entry;
    }

    /** Bound row hydration and composite-key bind parameters, without limiting the selected set. */
    private function lockedBatches(string $table, array $ids, ?TelegramUpdate $context): \Generator
    {
        for ($offset = 0, $total = count($ids); $offset < $total; $offset += 200) {
            $batchIds = array_slice($ids, $offset, 200);
            $rows = $this->ordered($this->selected($table, ['ids' => $batchIds], $context), $table)->lockForUpdate()->get();
            $this->assert($rows->count() === count($batchIds), 'plan', 'Выборка изменилась: запись удалена или недоступна. Запросите операцию заново.');

            yield $rows;
        }
    }

    private function columns(string $table, array $entry): array
    {
        return array_values(array_intersect($entry['read'] ?? Schema::getColumnListing($table), Schema::getColumnListing($table)));
    }

    private function columnDetails(string $table, array $entry): array
    {
        $foreignKeys = [];
        foreach (Schema::getForeignKeys($table) as $foreign) {
            foreach ($foreign['columns'] as $index => $column) {
                $foreignKeys[$column] = ['table' => $foreign['foreign_table'], 'column' => $foreign['foreign_columns'][$index] ?? 'id'];
            }
        }
        $readable = $this->columns($table, $entry);
        $result = [];
        foreach (Schema::getColumns($table) as $column) {
            if (! in_array($column['name'], $readable, true)) {
                continue;
            }
            $result[$column['name']] = ['type' => $column['type'], 'nullable' => $column['nullable'],
                'default' => $column['default'], 'writable' => array_key_exists($column['name'], $entry['writable']),
                'foreign_key' => $foreignKeys[$column['name']] ?? null];
        }

        return $result;
    }

    private function selected(string $table, array $selection, ?TelegramUpdate $context, bool $allowEmpty = false): Builder
    {
        $model = $this->entry($table)['model'];
        $query = $model::query();
        $this->assert(array_diff(array_keys($selection), ['ids', 'id_from', 'id_to', 'all', 'filters']) === [], 'selection', 'Неизвестный параметр выборки.');
        $this->assert($allowEmpty || $selection !== [], 'selection', 'Укажите ids, диапазон, filters или all:true. Для всех записей ID не нужны.');
        $this->assert(! array_key_exists('all', $selection) || $selection['all'] === true, 'selection', 'Для всей таблицы передайте all:true.');
        $this->assert(! array_key_exists('all', $selection) || count($selection) === 1, 'selection', 'all:true нельзя смешивать с другими условиями.');
        if (array_key_exists('ids', $selection)) {
            $this->assert(is_array($selection['ids']) && array_is_list($selection['ids']), 'ids', 'ids должен быть списком.');
            if ($table === 'attribute_definition_category') {
                $query->where(function ($builder) use ($selection): void {
                    $builder->whereRaw('0 = 1');
                    foreach ($selection['ids'] as $id) {
                        $this->assert(is_string($id) && preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/D', $id), 'ids', 'ID связи имеет формат attribute_definition_id:category_id.');
                        [$attribute, $category] = explode(':', $id);
                        $builder->orWhere(fn ($q) => $q->where('attribute_definition_id', $attribute)->where('category_id', $category));
                    }
                });
            } else {
                foreach ($selection['ids'] as $id) {
                    $this->assert((is_int($id) || is_string($id)) && ctype_digit((string) $id) && (int) $id > 0, 'ids', 'ID должен быть положительным целым.');
                }
                $query->whereKey($selection['ids']);
            }
        }
        foreach (['id_from' => '>=', 'id_to' => '<='] as $key => $operator) {
            if (array_key_exists($key, $selection)) {
                $this->assert($table !== 'attribute_definition_category' && filter_var($selection[$key], FILTER_VALIDATE_INT) !== false && (int) $selection[$key] > 0, $key, 'Граница ID должна быть положительным целым.');
                $query->where('id', $operator, $selection[$key]);
            }
        }
        $this->assert(! isset($selection['id_from'], $selection['id_to']) || $selection['id_from'] <= $selection['id_to'], 'selection', 'Нижняя граница ID больше верхней.');
        $filters = $selection['filters'] ?? [];
        $this->assert(is_array($filters) && array_is_list($filters) && ($allowEmpty || ! array_key_exists('filters', $selection) || $filters !== []), 'filters', 'Фильтры должны быть непустым списком условий.');
        foreach ($filters as $filter) {
            $this->assert(is_array($filter), 'filters', 'Фильтр должен быть объектом.');
            $column = $filter['column'] ?? '';
            $operator = $filter['operator'] ?? '=';
            $this->assert(in_array($column, $this->columns($table, $this->entry($table)), true) && in_array($operator, self::FILTERS, true), 'filters', 'Недопустимая колонка или оператор фильтра.');
            $value = $filter['value'] ?? null;
            if (in_array($operator, ['in', 'not_in'], true)) {
                $this->assert(is_array($value) && array_is_list($value) && collect($value)->every(fn ($item): bool => is_scalar($item) || $item === null), 'filters', 'in/not_in требует список простых значений.');
                $query->{$operator === 'in' ? 'whereIn' : 'whereNotIn'}($column, $value);
            } elseif (in_array($operator, ['is_null', 'not_null'], true)) {
                $query->{$operator === 'is_null' ? 'whereNull' : 'whereNotNull'}($column);
            } else {
                $this->assert(is_scalar($value) || $value === null, 'filters', 'Фильтр принимает простое значение.');
                if (in_array($operator, ['contains', 'starts_with'], true)) {
                    // Escape LIKE wildcards; the operator describes literal user text.
                    $value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);
                    $query->whereRaw($query->getQuery()->getGrammar()->wrap($column)." LIKE ? ESCAPE '\\'", [($operator === 'contains' ? '%' : '').$value.'%']);
                } else {
                    $query->where($column, $operator, $value);
                }
            }
        }
        $this->applyScope($query, $table, $context);

        return $query;
    }

    private function applyScope(Builder $query, string $table, ?TelegramUpdate $context): void
    {
        if (! $context) {
            return;
        }
        if ($table === 'product_drafts') {
            $query->where(fn ($q) => $q->whereNull('requested_by_telegram_user_id')->orWhere('requested_by_telegram_user_id', '')->orWhere('requested_by_telegram_user_id', $context->telegram_user_id))
                ->where(function ($q) use ($context): void {
                    $q->whereHas('telegramUpdate', fn ($t) => $t->where('chat_id', $context->chat_id))
                        ->orWhere(function ($legacy) use ($context): void {
                            $legacy->whereDoesntHave('telegramUpdate', fn ($t) => $t->whereNotNull('chat_id')->where('chat_id', '!=', ''))
                                ->where(fn ($chat) => $chat->whereNull('telegram_review_chat_id')->orWhere('telegram_review_chat_id', '')->orWhere('telegram_review_chat_id', $context->chat_id));
                        });
                });
        } elseif ($table === 'product_draft_media' || $table === 'product_source_page_evidence') {
            $relation = $table === 'product_draft_media' ? 'draft' : 'productDraft';
            $query->whereHas($relation, fn ($drafts) => $this->applyScope($drafts, 'product_drafts', $context));
        } elseif ($table === 'telegram_updates') {
            $query->where('chat_id', $context->chat_id)->where('telegram_user_id', $context->telegram_user_id);
        } elseif (in_array($table, ['ai_runs', 'ai_operations', 'product_source_attempts'], true)) {
            $query->whereHas('telegramUpdate', fn ($updates) => $this->applyScope($updates, 'telegram_updates', $context));
        }
    }

    private function ordered(Builder $query, string $table): Builder
    {
        return $table === 'attribute_definition_category' ? $query->orderBy('attribute_definition_id')->orderBy('category_id') : $query->orderBy('id');
    }

    private function validateValues(string $table, array $values, string $action, array $refs): void
    {
        $entry = $this->entry($table);
        $this->assert(array_diff(array_keys($values), array_keys($entry['writable'])) === [], 'values', 'Колонка защищена или не существует. Сначала вызовите описание таблиц; подтверждения, ID, даты и служебные поля не меняются произвольно.');
        $validated = $values;
        foreach ($values as $column => $value) {
            if (is_array($value) && array_key_exists('$ref', $value)) {
                $this->assert(count($value) === 1 && is_string($value['$ref']) && preg_match('/^([a-zA-Z][a-zA-Z0-9_]{0,63})\.id$/D', $value['$ref'], $matches), 'values', 'Некорректная ссылка на созданную запись.');
                $target = $refs[$matches[1]] ?? null;
                $expected = $this->foreignTable($column);
                $this->assert($expected !== null && $target === $expected, 'values', 'Ссылка должна указывать на ранее создаваемую запись подходящей таблицы.');
                unset($validated[$column]);
            }
        }
        $rules = [];
        foreach ($validated as $column => $value) {
            $rule = $entry['writable'][$column];
            $rules[$column] = is_array($rule) ? $rule : explode('|', $rule);
        }
        Validator::make($validated, $rules)->validate();
        if ($table === 'product_drafts' && isset($values['specifications'])) {
            Validator::make(['specifications' => $values['specifications']], [
                'specifications' => 'array', 'specifications.*' => 'array',
                'specifications.*.key' => 'required|string|max:100', 'specifications.*.name' => 'required|string|max:255',
                'specifications.*.value' => 'required|string|max:5000', 'specifications.*.unit' => 'nullable|string|max:24',
                'specifications.*.numeric_value' => 'nullable|numeric', 'specifications.*.boolean_value' => 'nullable|boolean',
                'specifications.*.source_url' => 'nullable|url:http,https|max:8192', 'specifications.*.source_excerpt' => 'nullable|string|max:5000',
                'specifications.*.group' => 'nullable|array:id,name,name_cs', 'specifications.*.group.id' => 'nullable|integer|min:1|exists:attribute_groups,id',
                'specifications.*.group.name' => 'nullable|string|max:255', 'specifications.*.group.name_cs' => 'nullable|string|max:255',
            ])->validate();
            $this->assert(array_is_list($values['specifications']) && count(array_unique(array_column($values['specifications'], 'key'))) === count($values['specifications']), 'specifications', 'Характеристики передаются списком с уникальными ключами.');
        }
        if ($action === 'create') {
            $required = match ($table) {
                'products' => ['title', 'category_id'], 'product_variants' => ['product_id'],
                'attribute_values' => ['attribute_definition_id', 'value'],
                'attribute_definition_category' => ['attribute_definition_id', 'category_id'],
                'category_translations' => ['category_id', 'locale', 'name'],
                'attribute_definitions' => ['label'], 'attribute_groups', 'categories', 'brands' => ['name'],
                'product_sources' => ['product_id', 'title', 'url'], 'product_source_domains' => ['domain'], 'product_drafts' => ['title'],
                default => [],
            };
            foreach ($required as $column) {
                $this->assert(isset($values[$column]), 'values.'.$column, 'Обязательное поле для создания: '.$column);
            }
        }
        if ($action === 'update' && $table === 'attribute_definition_category') {
            $this->assert(! isset($values['attribute_definition_id']) && ! isset($values['category_id']), 'values', 'Ключи связи неизменяемы; удалите старую связь и создайте новую.');
        }
        if (isset($values['translations'])) {
            foreach ($values['translations'] as $locale => $translation) {
                $this->assert(array_key_exists($locale, CatalogLocales::CONTENT_TRANSLATIONS), 'translations', 'Язык перевода не поддерживается каталогом.');
                if (in_array($table, ['attribute_groups', 'attribute_definitions', 'attribute_values'], true)) {
                    $this->assert(is_string($translation), 'translations', 'Перевод названия/значения должен быть строкой.');
                } else {
                    $keys = $table === 'product_drafts' ? ['title', 'description', 'specifications'] : ['title', 'description'];
                    $this->assert(is_array($translation) && array_diff(array_keys($translation), $keys) === [], 'translations', 'Перевод карточки содержит только title, description и (для черновика) specifications.');
                    foreach ($translation as $field => $text) {
                        if ($field === 'specifications') {
                            Validator::make(['specifications' => $text], ['specifications' => 'array', 'specifications.*' => 'array:key,name,value',
                                'specifications.*.key' => 'required|string', 'specifications.*.name' => 'required|string', 'specifications.*.value' => 'required|string'])->validate();
                        } else {
                            $this->assert(is_string($text), 'translations', 'Перевод поля должен быть текстом.');
                        }
                    }
                }
            }
        }
    }

    private function foreignTable(string $column): ?string
    {
        return ['attribute_group_id' => 'attribute_groups', 'attribute_definition_id' => 'attribute_definitions',
            'category_id' => 'categories', 'parent_id' => 'categories', 'brand_id' => 'brands',
            'product_id' => 'products', 'product_variant_id' => 'product_variants'][$column] ?? null;
    }

    private function resolveReferences(array $values, array $references): array
    {
        foreach ($values as $column => $value) {
            if (is_array($value) && array_key_exists('$ref', $value)) {
                $parts = explode('.', (string) $value['$ref']);
                $this->assert(count($parts) === 2 && $parts[1] === 'id' && isset($references[$parts[0]]['id']), 'values', 'Создаваемая запись для ссылки отсутствует.');
                $values[$column] = $references[$parts[0]]['id'];
            }
        }

        return $values;
    }

    private function assertRowAllowed(Model $row, string $action, array $values, ?TelegramUpdate $context): void
    {
        $draft = $row instanceof ProductDraft ? $row : ($row instanceof ProductDraftMedia ? $row->draft : null);
        if ($draft?->exists) {
            $this->assert(! $context || app(DraftAccess::class)->belongsToContext($draft, $context), 'scope', 'Черновик принадлежит другому оператору или чату.');
            $source = $draft->telegramUpdate;
            $active = $source && in_array($source->status, ['pending', 'queued', 'processing'], true) && ! $source->cancel_requested_at;
            $active = $active || in_array($draft->gallery_status, ['queued', 'searching', 'running', 'processing'], true);
            $active = $active || ($source && AiRun::query()->where('telegram_update_id', $source->id)->whereIn('status', ['queued', 'running'])->exists());
            foreach (['draft-gallery-retrain', 'draft-gallery-restage', 'draft-gallery-continue', 'draft-gallery-topup', 'draft-photo-actions', 'draft-fields', 'draft-enrich', 'draft-upload'] as $operation) {
                $active = $active || Cache::has("{$operation}:{$draft->id}:queued");
            }
            $active = $active || AiOperation::query()->where('target_type', ProductDraft::class)->where('target_id', $draft->id)->whereIn('status', ['queued', 'running', 'processing'])->exists();
            $this->assert(! $active, 'draft', 'Черновик ещё обрабатывается. Отмените его активный поиск и повторите операцию: данные не удалены и не изменены.');
            if ($action !== 'delete') {
                $this->assert(! in_array($draft->status, ['approved', 'rejected'], true), 'draft', 'Завершённый черновик не редактируется; изменяйте товар каталога или создайте новый черновик.');
            }
        }
        if ($row instanceof Product && isset($values['status']) && $values['status'] === 'published') {
            $this->assert($row->exists && $row->status === 'published', 'status', 'Публикация нового товара выполняется подтверждением готового черновика.');
        }
        if ($row instanceof Product && array_key_exists('category_id', $values) && $values['category_id'] === null) {
            $this->assert(false, 'category_id', 'Товару нужна категория.');
        }
        if ($action === 'delete') {
            if ($row instanceof Category) {
                $this->assert(! $row->products()->exists() && ! $row->children()->exists(), 'category', 'Категория используется товарами или дочерними категориями. Сначала перенесите их.');
            } elseif ($row instanceof Brand) {
                $this->assert(! $row->products()->exists(), 'brand', 'Бренд используется товарами. Сначала измените их бренд.');
            } elseif ($row instanceof AttributeDefinition) {
                $this->assert(! $row->values()->exists(), 'attribute', 'У характеристики есть значения товаров. Сначала удалите или перенесите их отдельной явной командой.');
            }
        }
    }

    private function save(Model $row, array $values, ?TelegramUpdate $context): void
    {
        if ($row instanceof Product && ! $row->exists) {
            $values += ['status' => 'draft', 'is_active' => false];
        }
        if ($row instanceof ProductDraft && ! $row->exists) {
            $this->assert($context !== null && $context->exists, 'context', 'Создание Telegram-черновика требует текущего сообщения оператора.');
            $run = AiRun::query()->create(['telegram_update_id' => $context->id, 'provider' => 'local', 'model' => 'database_operator',
                'prompt' => (string) $context->text, 'started_at' => now(),
                'status' => 'completed', 'response' => ['no_provider_invocation' => true], 'usage' => [], 'completed_at' => now()]);
            $row->forceFill(['telegram_update_id' => $context->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => $context->telegram_user_id,
                'status' => 'pending_review', 'specifications' => [], 'sources' => [], 'image_urls' => []]);
        }
        if ($row instanceof ProductDraft && $row->exists && ! array_key_exists('translations', $values)) {
            $translations = $row->translations;
            if (is_array($translations)) {
                $textChanged = collect(['title', 'description'])->contains(fn ($field): bool => array_key_exists($field, $values) && $values[$field] !== $row->{$field});
                $changedKeys = [];
                if (isset($values['specifications'])) {
                    $before = collect($row->specifications ?? [])->keyBy('key');
                    $after = collect($values['specifications'])->keyBy('key');
                    foreach ($before->keys()->merge($after->keys())->unique() as $key) {
                        if (($before->get($key)['value'] ?? null) !== ($after->get($key)['value'] ?? null) || ($before->get($key)['name'] ?? null) !== ($after->get($key)['name'] ?? null)) {
                            $changedKeys[] = $key;
                        }
                    }
                }
                foreach ($translations as &$translation) {
                    if (! is_array($translation)) {
                        continue;
                    }
                    if ($textChanged) {
                        unset($translation['title'], $translation['description']);
                    }
                    if ($changedKeys !== [] && is_array($translation['specifications'] ?? null)) {
                        $translation['specifications'] = array_values(array_filter($translation['specifications'], fn ($item): bool => is_array($item) && ! in_array($item['key'] ?? null, $changedKeys, true)));
                    }
                }
                unset($translation);
                $values['translations'] = $translations;
            }
        }
        $previousProductId = null;
        // category_id is intentionally set server-side on this existing relation model.
        $row->forceFill($values);
        if ($row instanceof AttributeDefinition && $row->exists && $row->isDirty('label') && ! array_key_exists('translations', $values)) {
            $row->translations = null;
        }
        if ($row instanceof Product) {
            $affinity = Category::query()->find($row->category_id)?->product_type_affinity;
            $this->assert(! $affinity || ! isset($values['product_type']) || $values['product_type'] === $affinity, 'product_type', 'Технический тип определяется категорией. Измените категорию или её привязку типа.');
            $row->product_type = $affinity ?: ($row->product_type ?: 'other');
        }
        if ($row instanceof ProductVariant && $row->exists && $row->isDirty('product_id')) {
            $previousProductId = (int) $row->getOriginal('product_id');
            Product::query()->whereIn('id', [$previousProductId, $row->product_id])->orderBy('id')->lockForUpdate()->get();
            $this->assert(! $row->attributes()->exists() && ! $row->media()->exists() && ! $row->sources()->exists(), 'product_id', 'Нельзя переносить комплектацию со связанными характеристиками, фото или источниками.');
            $this->assert(! ProductDraft::query()->where('approved_variant_id', $row->id)->lockForUpdate()->exists(), 'product_id', 'Нельзя переносить комплектацию, на которую ссылается подтверждённый черновик.');
        }
        if ($row instanceof AttributeDefinition && $row->exists && $row->isDirty('is_variant')) {
            $this->assert(! $row->values()->exists(), 'is_variant', 'Область характеристики со значениями меняется только с явным переносом самих значений.');
        }
        if ($row instanceof AttributeDefinition && $row->exists && $row->isDirty('data_type')) {
            $this->assert(! $row->values()->exists(), 'data_type', 'Тип характеристики со значениями меняется только с явным преобразованием значений.');
        }
        if ($row instanceof AttributeValue) {
            $this->assert((bool) $row->product_id !== (bool) $row->product_variant_id, 'owner', 'Значение принадлежит либо товару, либо одной комплектации.');
            $definition = AttributeDefinition::query()->findOrFail($row->attribute_definition_id);
            $this->assert((bool) $definition->is_variant === (bool) $row->product_variant_id, 'owner', 'Область значения должна совпадать с областью характеристики.');
            if ($row->isDirty('value')) {
                $row->normalized_value = Str::lower(trim($row->value));
                if (! array_key_exists('numeric_value', $values)) {
                    $row->numeric_value = $definition->data_type === 'number' && is_numeric($row->value) ? $row->value : null;
                }
                if (! array_key_exists('boolean_value', $values)) {
                    $row->boolean_value = $definition->data_type === 'boolean' ? filter_var($row->value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
                }
            }
            if ($definition->data_type === 'number') {
                $this->assert(is_numeric($row->numeric_value), 'numeric_value', 'Для числовой характеристики нужно числовое значение.');
            }
            if ($definition->data_type === 'boolean') {
                $this->assert($row->boolean_value !== null, 'boolean_value', 'Для логической характеристики нужно true/false.');
            }
        }
        if ($row instanceof ProductMedia || $row instanceof ProductSource) {
            $variantId = $row->product_variant_id;
            $this->assert(! $variantId || ProductVariant::query()->whereKey($variantId)->where('product_id', $row->product_id)->exists(), 'product_variant_id', 'Комплектация относится к другому товару.');
        }
        if ($row instanceof ProductSource) {
            $row->domain = mb_strtolower((string) parse_url($row->url, PHP_URL_HOST));
        }
        $unique = match (true) {
            $row instanceof Product => ['slug', 'canonical_key'],
            $row instanceof Brand, $row instanceof Category => ['slug'],
            $row instanceof AttributeDefinition => ['key'],
            $row instanceof AttributeGroup => ['name'],
            $row instanceof ProductSourceDomain => ['domain'],
            default => [],
        };
        foreach ($unique as $column) {
            if (filled($row->{$column}) && (! $row->exists || $row->isDirty($column))) {
                Validator::make([$column => $row->{$column}], [$column => [Rule::unique($row->getTable(), $column)->ignore($row->exists ? $row->getKey() : null)]])->validate();
            }
        }
        if ($row instanceof ProductVariant) {
            foreach (['sku', 'gtin'] as $column) {
                if (filled($row->{$column})) {
                    Validator::make([$column => $row->{$column}], [$column => [Rule::unique($row->getTable(), $column)->where('condition', $row->condition ?: 'new')->ignore($row->exists ? $row->id : null)]])->validate();
                }
            }
        }
        if ($row instanceof AttributeValue) {
            $ownerColumn = $row->product_variant_id ? 'product_variant_id' : 'product_id';
            $duplicate = AttributeValue::query()->where('attribute_definition_id', $row->attribute_definition_id)
                ->where($ownerColumn, $row->{$ownerColumn})->when($row->exists, fn ($query) => $query->whereKeyNot($row->id))->exists();
            $this->assert(! $duplicate, 'attribute_definition_id', 'У товара/комплектации уже есть значение этой характеристики.');
        }
        if ($row instanceof CategoryTranslation) {
            $duplicate = CategoryTranslation::query()->where('category_id', $row->category_id)->where('locale', $row->locale)
                ->when($row->exists, fn ($query) => $query->whereKeyNot($row->id))->exists();
            $this->assert(! $duplicate, 'locale', 'Перевод этой категории на выбранный язык уже есть.');
        }
        if ($row instanceof AttributeCategoryLink && ! $row->exists) {
            $this->assert(! AttributeCategoryLink::query()->where('attribute_definition_id', $row->attribute_definition_id)->where('category_id', $row->category_id)->exists(), 'category_id', 'Связь характеристики с категорией уже существует.');
        }
        if ($row instanceof ProductVariant || $row instanceof ProductMedia) {
            Product::query()->whereKey($row->product_id)->lockForUpdate()->first();
        } elseif ($row instanceof ProductDraftMedia) {
            ProductDraft::query()->whereKey($row->product_draft_id)->lockForUpdate()->first();
        }
        $row->save();
        if ($row instanceof ProductVariant && $row->is_default) {
            ProductVariant::query()->where('product_id', $row->product_id)->whereKeyNot($row->id)->update(['is_default' => false]);
        } elseif ($row instanceof ProductVariant && ! ProductVariant::query()->where('product_id', $row->product_id)->where('is_default', true)->exists()) {
            ProductVariant::query()->where('product_id', $row->product_id)->orderBy('id')->first()?->update(['is_default' => true]);
        }
        if (($row instanceof ProductMedia || $row instanceof ProductDraftMedia) && $row->is_primary) {
            $siblings = $row instanceof ProductMedia ? ProductMedia::query()->where('product_id', $row->product_id)->where('product_variant_id', $row->product_variant_id) : ProductDraftMedia::query()->where('product_draft_id', $row->product_draft_id);
            $siblings->whereKeyNot($row->id)->update(['is_primary' => false]);
        }
        if ($previousProductId !== null && ! ProductVariant::query()->where('product_id', $previousProductId)->where('is_default', true)->exists()) {
            ProductVariant::query()->where('product_id', $previousProductId)->orderBy('id')->first()?->update(['is_default' => true]);
        }
    }

    private function delete(Model $row): void
    {
        // Cascades do not fire model observers: enumerate media before deleting parents.
        if ($row instanceof Product || $row instanceof ProductVariant || $row instanceof ProductDraft) {
            foreach ($row->media()->lockForUpdate()->get() as $media) {
                $media->delete(); // cleanup job itself is explicitly afterCommit.
            }
        }
        if ($row instanceof ProductVariant) {
            $productId = $row->product_id;
            $wasDefault = $row->is_default;
            $row->delete();
            if ($wasDefault && ($replacement = ProductVariant::query()->where('product_id', $productId)->orderBy('id')->lockForUpdate()->first())) {
                $replacement->update(['is_default' => true]);
            }

            return;
        }
        $row->delete();
    }

    /** Counts describe actual FK cascades/detachments already covered by the row fingerprint. */
    private function deletionEffects(Model $row): array
    {
        $effects = [];
        $add = function (string $table, int $count, string $action = 'delete', array $values = []) use (&$effects): void {
            if ($count > 0) {
                $effects[] = compact('table', 'count', 'action', 'values');
            }
        };
        if ($row instanceof AttributeGroup) {
            $add('attribute_definitions', $row->attributeDefinitions()->count(), 'update', ['attribute_group_id' => null]);
        } elseif ($row instanceof AttributeDefinition) {
            $add('attribute_definition_category', $row->categories()->count());
        } elseif ($row instanceof Category) {
            $add('category_translations', $row->translations()->count());
            $add('attribute_definition_category', $row->attributeDefinitions()->count());
        } elseif ($row instanceof Product) {
            $variantIds = $row->variants()->pluck('id')->all();
            $add('product_variants', count($variantIds));
            $add('product_media', $row->media()->count());
            $add('product_sources', $row->sources()->count());
            $add('attribute_values', AttributeValue::query()->where(fn ($query) => $query
                ->where('product_id', $row->id)->orWhereIn('product_variant_id', $variantIds))->count());
            $add('product_drafts', ProductDraft::query()->where('approved_product_id', $row->id)->count(), 'update', ['approved_product_id' => null]);
            $add('product_drafts', ProductDraft::query()->whereIn('approved_variant_id', $variantIds)->count(), 'update', ['approved_variant_id' => null]);
        } elseif ($row instanceof ProductVariant) {
            $add('attribute_values', $row->attributes()->count());
            $add('product_media', $row->media()->count());
            $add('product_sources', $row->sources()->count());
            $add('product_drafts', ProductDraft::query()->where('approved_variant_id', $row->id)->count(), 'update', ['approved_variant_id' => null]);
        } elseif ($row instanceof ProductDraft) {
            $add('product_draft_media', $row->media()->count());
            $add('product_source_page_evidence', ProductSourcePageEvidence::query()->where('product_draft_id', $row->id)->count());
            $add('product_sources', ProductSource::query()->where('product_draft_id', $row->id)->count(), 'update', ['product_draft_id' => null]);
            $add('product_source_attempts', ProductSourceAttempt::query()->where('product_draft_id', $row->id)->count(), 'update', ['product_draft_id' => null]);
        }

        return $effects;
    }

    private function fingerprint(Model $row, bool $lock = false): string
    {
        $snapshot = ['row' => $row->getAttributes()];
        if ($row instanceof Product || $row instanceof ProductDraft) {
            $snapshot['catalog_revision'] = app(CatalogEditRevision::class)->fingerprint($row, $lock);
        }
        $relations = match (true) {
            $row instanceof Product => ['drafts'],
            $row instanceof ProductVariant => ['attributes', 'media', 'sources'],
            $row instanceof Category => ['children', 'products', 'translations', 'attributeDefinitions'],
            $row instanceof Brand => ['products'], $row instanceof AttributeDefinition => ['values', 'categories'],
            $row instanceof AttributeGroup => ['attributeDefinitions'], default => [],
        };
        foreach ($relations as $relation) {
            $query = $row->{$relation}();
            if ($lock) {
                $query->lockForUpdate();
            }
            $snapshot[$relation] = $query->get()->map(fn (Model $model): array => $model->getAttributes() + ($model->relationLoaded('pivot') ? ['_pivot' => $model->pivot->getAttributes()] : []))->sortBy('id')->values()->all();
        }
        if ($row instanceof ProductVariant) {
            $snapshot['approved_drafts'] = ProductDraft::query()->where('approved_variant_id', $row->id)
                ->when($lock, fn ($query) => $query->lockForUpdate())->orderBy('id')->get()->map(fn ($draft): array => $draft->getAttributes())->all();
        }
        if ($row instanceof ProductDraft) {
            foreach ([ProductSource::class, ProductSourceAttempt::class, ProductSourcePageEvidence::class] as $model) {
                $snapshot[$model] = $model::query()->where('product_draft_id', $row->id)
                    ->when($lock, fn ($query) => $query->lockForUpdate())->orderBy('id')->get()->map(fn ($dependent): array => $dependent->getAttributes())->all();
            }
        }
        if ($row instanceof Product) {
            $snapshot['variant_approved_drafts'] = ProductDraft::query()->whereIn('approved_variant_id', $row->variants()->select('id'))
                ->when($lock, fn ($query) => $query->lockForUpdate())->orderBy('id')->get()->map(fn ($draft): array => $draft->getAttributes())->all();
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function safeRow(Model $row, array $columns): array
    {
        $result = [];
        foreach ($columns as $column) {
            $value = $row->getAttribute($column);
            if (is_array($value) && strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 12000) {
                $value = ['_truncated' => true, '_note' => 'Большое поле; сузьте запрос.'];
            } elseif (is_string($value) && mb_strlen($value) > 3000) {
                $value = mb_substr($value, 0, 3000).'…';
            }
            $result[$column] = $value;
        }

        return $result;
    }

    private function scope(?TelegramUpdate $context): ?array
    {
        return $context ? ['chat_id' => (string) $context->chat_id, 'telegram_user_id' => (string) $context->telegram_user_id] : null;
    }

    private function scopeNote(string $table, ?TelegramUpdate $context): string
    {
        return $context && in_array($table, ['product_drafts', 'product_draft_media', 'product_source_page_evidence', 'ai_runs', 'ai_operations', 'telegram_updates', 'product_source_attempts'], true)
            ? 'Только записи текущего оператора в текущем чате.' : 'Общий каталог/справочник.';
    }

    private function assert(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
