<?php

namespace App\Services\Maintenance;

use App\Jobs\DeleteUnreferencedMedia;
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
use App\Models\ProductSourcePageEvidence;
use App\Models\ProductSourcePageRule;
use App\Models\ProductSourceStat;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Explicit admin maintenance. No truncate, queue purge, global cache flush or ID reuse. */
class CatalogCleanupService
{
    private const PRESETS = ['catalog', 'drafts', 'test_data', 'attributes', 'attribute_groups'];

    /** Longest interactive job is 2100s; retain its whole quiet call plus a 120s margin. */
    private const ACTIVE_WINDOW_SECONDS = 2220;

    private const LABELS = [
        'products' => 'Товары', 'product_variants' => 'Комплектации', 'product_media' => 'Фото каталога',
        'product_sources' => 'Источники товаров', 'attribute_values' => 'Значения характеристик',
        'product_drafts' => 'Черновики', 'product_draft_media' => 'Фото черновиков',
        'product_source_page_evidence' => 'Сохранённые страницы черновиков',
        'attribute_definition_category' => 'Привязки характеристик к категориям',
        'attribute_definitions' => 'Определения характеристик', 'attribute_groups' => 'Группы характеристик',
        'categories' => 'Категории', 'category_translations' => 'Переводы категорий', 'brands' => 'Бренды',
        'product_gallery_recipes' => 'Рецепты галерей (кроме заблокированных доменов)',
        'product_gallery_recipe_versions' => 'Версии рецептов', 'product_source_stats' => 'Статистика источников',
        'product_source_page_rules' => 'Автоматические правила страниц',
        'agent_conversation_messages' => 'Сообщения AI-диалогов', 'agent_conversations' => 'AI-диалоги',
        'telegram_chat_states' => 'Контекст Telegram-диалогов',
    ];

    private const MODELS = [
        'products' => Product::class, 'product_variants' => ProductVariant::class, 'product_media' => ProductMedia::class,
        'product_sources' => ProductSource::class, 'attribute_values' => AttributeValue::class,
        'product_drafts' => ProductDraft::class, 'product_draft_media' => ProductDraftMedia::class,
        'product_source_page_evidence' => ProductSourcePageEvidence::class,
        'attribute_definitions' => AttributeDefinition::class, 'attribute_groups' => AttributeGroup::class,
        'categories' => Category::class, 'category_translations' => CategoryTranslation::class, 'brands' => Brand::class,
        'product_gallery_recipes' => ProductGalleryRecipe::class, 'product_gallery_recipe_versions' => ProductGalleryRecipeVersion::class,
        'product_source_stats' => ProductSourceStat::class, 'product_source_page_rules' => ProductSourcePageRule::class,
    ];

    private const PRESERVED = [
        'Администраторы и пользователи', 'Настройки и API-ключи', 'Telegram updates, AI runs и AI operations',
        'История попыток источников и доставки сообщений', 'Подсказки доменов и блокировки источников',
        'Очередь заданий и глобальный кэш', 'Счётчики ID',
    ];

    public function prepare(string $preset, array $options, User $actor): array
    {
        $actor = $this->authorize($actor);
        $this->assert(in_array($preset, self::PRESETS, true), 'preset', 'Неизвестный режим очистки.');
        $this->assert(array_diff(array_keys($options), ['dictionaries', 'recipes_history']) === [], 'options', 'Неизвестная настройка очистки.');
        foreach ($options as $value) {
            $this->assert(is_bool($value), 'options', 'Настройки очистки должны быть true/false.');
        }
        $options += ['dictionaries' => false, 'recipes_history' => false];
        $this->assert($preset === 'test_data' || ! $options['dictionaries'] && ! $options['recipes_history'], 'options', 'Дополнительные словари и рецепты выбираются только для общей тестовой очистки.');

        return DB::transaction(function () use ($preset, $options, $actor): array {
            $deletes = $this->deleteTables($preset, $options);
            $witnesses = $this->witnessTables($deletes);
            $plan = ['version' => 1, 'plan_id' => (string) Str::uuid(), 'preset' => $preset, 'options' => $options,
                'actor_id' => $actor->id, 'expires_at' => now()->addMinutes(15)->getTimestamp(),
                'counts' => [], 'labels' => [], 'tables' => [], 'blockers' => $this->blockers(),
                'preserved' => self::PRESERVED, 'effects' => [],
                'activity_policy' => 'Исторический статус без активности более 37 минут сам по себе не блокирует очистку. История не изменяется; задания и маркеры очереди по-прежнему проверяются.'];
            foreach (array_values(array_unique([...$deletes, ...$witnesses])) as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $delete = in_array($table, $deletes, true);
                $snapshot = $this->snapshot($table, $delete);
                $plan['tables'][$table] = [...$snapshot, 'delete' => $delete];
                if ($delete) {
                    $plan['counts'][$table] = count($snapshot['ids']);
                    $plan['labels'][$table] = self::LABELS[$table];
                }
            }
            if (in_array('attribute_groups', $deletes, true) && ! in_array('attribute_definitions', $deletes, true)) {
                $plan['effects']['ungrouped_attributes'] = AttributeDefinition::query()->whereNotNull('attribute_group_id')->count();
            }
            if (in_array('products', $deletes, true) && ! in_array('product_drafts', $deletes, true)) {
                $plan['effects']['draft_catalog_links_removed'] = ProductDraft::query()
                    ->where(fn ($query) => $query->whereNotNull('approved_product_id')->orWhereNotNull('approved_variant_id'))->count();
            }
            if ($options['recipes_history']) {
                $plan['effects']['preserved_blocked_domains'] = ProductGalleryRecipe::query()->where('source_blocked', true)->count();
            }
            $plan['signature'] = $this->signature($plan);

            return $plan;
        });
    }

    public function execute(array $plan, User $actor): array
    {
        $actor = $this->authorize($actor);
        $signature = $plan['signature'] ?? '';
        unset($plan['signature']);
        $this->assert(is_string($signature) && hash_equals($this->signature($plan), $signature), 'plan', 'План очистки изменён. Откройте подтверждение заново.');
        $this->assert(($plan['version'] ?? null) === 1 && ($plan['actor_id'] ?? null) === $actor->id
            && is_string($plan['plan_id'] ?? null) && Str::isUuid($plan['plan_id']), 'plan', 'План принадлежит другому администратору или имеет неподдерживаемый формат.');

        return Cache::lock('admin-cleanup:'.$plan['plan_id'], 300)->block(5, function () use ($plan, $actor): array {
            // The audit starts outside the mutation transaction and survives a rollback.
            $operation = AiOperation::query()->firstOrCreate(['idempotency_key' => 'admin-cleanup:'.$plan['plan_id']], [
                'tool' => self::class, 'action' => 'admin_cleanup', 'target_type' => 'maintenance', 'target_id' => $actor->id,
                'status' => 'pending', 'payload' => ['actor_user_id' => $actor->id, 'preset' => $plan['preset'],
                    'options' => $plan['options'], 'counts' => $plan['counts'], 'plan_id' => $plan['plan_id']],
            ]);
            if ($operation->status === 'completed') {
                return $operation->result;
            }
            $operation->update(['status' => 'running', 'error' => null]);
            try {
                $this->assert(is_int($plan['expires_at'] ?? null) && $plan['expires_at'] > now()->getTimestamp(), 'plan', 'Подтверждение очистки устарело. Откройте его заново.');
                $this->assert(($plan['blockers'] ?? []) === [], 'cleanup', implode(' ', $plan['blockers'] ?? []));
                $result = DB::transaction(function () use ($plan, $actor, $operation): array {
                    $this->authorize($actor);
                    $blockers = $this->blockers($operation->id);
                    $this->assert($blockers === [], 'cleanup', implode(' ', $blockers));
                    foreach ($plan['tables'] as $table => $expected) {
                        $actual = $this->snapshot($table, $expected['delete'], lock: true);
                        $this->assert($actual['ids'] === $expected['ids'] && hash_equals($expected['fingerprint'], $actual['fingerprint']),
                            'plan', 'Данные изменились после предпросмотра ('.$table.'). Ничего не удалено; откройте подтверждение заново.');
                    }
                    $deleted = [];
                    foreach ($this->deleteTables($plan['preset'], $plan['options']) as $table) {
                        $ids = $plan['tables'][$table]['ids'] ?? [];
                        $deleted[$table] = $this->deleteRows($table, $ids);
                    }
                    $result = ['status' => 'completed', 'operation_id' => $operation->id, 'preset' => $plan['preset'],
                        'deleted' => $deleted, 'preserved' => self::PRESERVED, 'effects' => $plan['effects']];
                    $operation->update(['status' => 'completed', 'executed_at' => now(), 'result' => $result, 'error' => null]);

                    return $result;
                });

                return $result;
            } catch (Throwable $exception) {
                // Queue dispatch/file callbacks run after a successful commit.
                // Their failure cannot be reported as a database rollback.
                $operation->refresh();
                if ($operation->status === 'completed') {
                    $result = $operation->result;
                    $result['cleanup_warning'] = 'Данные удалены, но завершить очистку файлов не удалось. Проверьте очередь media и журнал; повтор этой операции не удалит данные заново.';
                    $operation->update(['result' => $result, 'error' => mb_substr($exception->getMessage(), 0, 2000)]);

                    return $result;
                }
                $operation->refresh()->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'executed_at' => now(),
                    'result' => ['status' => 'failed', 'counts' => $plan['counts'], 'rolled_back' => true]]);
                throw $exception;
            }
        });
    }

    private function authorize(User $actor): User
    {
        $current = $actor->exists ? User::query()->find($actor->id) : null;
        if (! $current || ! $current->canAccessPanel(Filament::getPanel('admin'))) {
            throw new AuthorizationException('Очистка данных доступна только администратору панели.');
        }

        return $current;
    }

    /** Deletion order leaves files to model hooks, then satisfies foreign keys. */
    private function deleteTables(string $preset, array $options): array
    {
        $tables = [];
        if (in_array($preset, ['catalog', 'test_data'], true)) {
            $tables = ['product_media', 'attribute_values', 'product_sources', 'product_variants', 'products'];
        }
        if (in_array($preset, ['drafts', 'test_data'], true)) {
            $tables = [...$tables, 'product_draft_media', 'product_source_page_evidence', 'product_drafts'];
        }
        if ($preset === 'attributes' || ($options['dictionaries'] ?? false)) {
            $tables = [...$tables, 'attribute_values', 'attribute_definition_category', 'attribute_definitions'];
        }
        if ($preset === 'attribute_groups' || ($options['dictionaries'] ?? false)) {
            $tables[] = 'attribute_groups';
        }
        if ($options['dictionaries'] ?? false) {
            $tables = [...$tables, 'category_translations', 'categories', 'brands'];
        }
        if ($options['recipes_history'] ?? false) {
            $tables = [...$tables, 'product_source_page_rules', 'product_gallery_recipe_versions', 'product_gallery_recipes', 'product_source_stats'];
        }
        if ($preset === 'test_data') {
            $tables = [...$tables, 'agent_conversation_messages', 'agent_conversations', 'telegram_chat_states'];
        }

        return array_values(array_unique($tables));
    }

    /** Preserved rows whose foreign keys will be detached must not silently change after preview. */
    private function witnessTables(array $deletes): array
    {
        $tables = [];
        if (in_array('products', $deletes, true) || in_array('product_variants', $deletes, true)) {
            $tables[] = 'product_drafts';
        }
        if (in_array('product_drafts', $deletes, true)) {
            $tables = [...$tables, 'product_sources', 'product_source_attempts'];
        }
        if (in_array('product_gallery_recipe_versions', $deletes, true)) {
            $tables[] = 'product_source_attempts';
        }
        if (in_array('attribute_groups', $deletes, true)) {
            $tables[] = 'attribute_definitions';
        }

        return array_values(array_diff(array_unique($tables), $deletes));
    }

    private function snapshot(string $table, bool $delete, bool $lock = false): array
    {
        $keys = $table === 'attribute_definition_category' ? ['attribute_definition_id', 'category_id'] : ['id'];
        $query = DB::table($table);
        foreach ($keys as $key) {
            $query->orderBy($key);
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $hash = hash_init('sha256');
        $ids = [];
        foreach ($query->cursor() as $row) {
            $data = (array) $row;
            hash_update($hash, json_encode($data, JSON_THROW_ON_ERROR)."\n");
            // Blocking a source is a setting, even though it shares the recipe table.
            if ($delete && ! ($table === 'product_gallery_recipes' && (bool) ($data['source_blocked'] ?? false))) {
                $ids[] = count($keys) === 1 ? $data[$keys[0]] : implode(':', array_map(fn ($key) => $data[$key], $keys));
            }
        }

        return ['ids' => $ids, 'fingerprint' => hash_final($hash)];
    }

    private function deleteRows(string $table, array $ids): int
    {
        $deleted = 0;
        for ($offset = 0, $total = count($ids); $offset < $total; $offset += 200) {
            $batch = array_slice($ids, $offset, 200);
            if (isset(self::MODELS[$table])) {
                $model = self::MODELS[$table];
                $rows = $model::query()->whereKey($batch)->orderBy('id')->lockForUpdate()->get();
                $this->assert($rows->count() === count($batch), 'cleanup', 'Выбранные записи изменились во время очистки ('.$table.'). Изменения отменены.');
                foreach ($rows as $row) {
                    $this->assert($row->delete() !== false, 'cleanup', 'Модель запретила удаление записи ('.$table.'). Изменения отменены.');
                    $deleted++;
                }
            } elseif ($table === 'attribute_definition_category') {
                foreach ($batch as $id) {
                    [$attributeId, $categoryId] = explode(':', $id);
                    $deleted += DB::table($table)->where('attribute_definition_id', $attributeId)->where('category_id', $categoryId)->delete();
                }
            } else {
                // Only allowlisted conversation rows have no file-owning model hooks.
                $deleted += DB::table($table)->whereIn('id', $batch)->delete();
            }
        }
        $this->assert($deleted === count($ids), 'cleanup', 'Удалено не всё из согласованной выборки ('.$table.'). Изменения отменены.');

        return $deleted;
    }

    private function blockers(?int $ownOperation = null): array
    {
        $reasons = [];
        // A killed worker can leave "running" in historical audit rows forever.
        // Do not rewrite that history or mistake it for a live process. A whole
        // job-length window protects quiet provider calls; jobs and queued
        // markers below remain vetoes regardless of their age.
        $recentSince = now()->subSeconds(self::ACTIVE_WINDOW_SECONDS);
        $activeUpdates = TelegramUpdate::query()->whereIn('status', ['queued', 'processing', 'delivering'])
            ->where(fn ($query) => $query->where('created_at', '>=', $recentSince)->orWhere('updated_at', '>=', $recentSince))
            ->orderBy('id')->limit(10)->pluck('id')->all();
        if ($activeUpdates !== []) {
            $reasons[] = 'Есть активные Telegram-запросы: #'.implode(', #', $activeUpdates).'. Завершите или отмените их и дождитесь остановки обработки.';
        }
        $activeRuns = AiRun::query()->whereIn('status', ['queued', 'running'])
            ->where(fn ($query) => $query->where('created_at', '>=', $recentSince)->orWhere('started_at', '>=', $recentSince)
                ->orWhere('updated_at', '>=', $recentSince)->orWhere('last_activity_at', '>=', $recentSince))
            ->orderBy('id')->limit(10)->pluck('id')->all();
        if ($activeRuns !== []) {
            $reasons[] = 'Есть работающие/ожидающие AI-запуски: #'.implode(', #', $activeRuns).'. Дождитесь завершения, включая помощник формы.';
        }
        $activeOperations = AiOperation::query()->whereIn('status', ['pending', 'queued', 'running', 'processing'])
            ->when($ownOperation, fn ($query) => $query->whereKeyNot($ownOperation))
            ->where(fn ($query) => $query->where('created_at', '>=', $recentSince)->orWhere('updated_at', '>=', $recentSince))
            ->orderBy('id')->limit(10)->pluck('id')->all();
        if ($activeOperations !== []) {
            $reasons[] = 'Есть незавершённые операции: #'.implode(', #', $activeOperations).'. Очистка не выполняется из-под работающего задания.';
        }
        if (Schema::hasTable('jobs')) {
            $jobIds = [];
            foreach (DB::table('jobs')->orderBy('id')->cursor() as $job) {
                $payload = json_decode($job->payload, true);
                $class = $payload['data']['commandName'] ?? $payload['displayName'] ?? '';
                if ($class !== DeleteUnreferencedMedia::class) {
                    $jobIds[] = $job->id;
                    if (count($jobIds) === 10) {
                        break;
                    }
                }
            }
            if ($jobIds !== []) {
                $reasons[] = 'В очереди есть задания: #'.implode(', #', $jobIds).'. Завершите/отмените обработку; очередь очисткой не удаляется.';
            }
        }
        $draftIds = [];
        foreach (ProductDraft::query()->orderBy('id')->cursor(['id', 'gallery_status', 'created_at', 'updated_at']) as $draft) {
            $recentDraft = $draft->created_at?->greaterThanOrEqualTo($recentSince) || $draft->updated_at?->greaterThanOrEqualTo($recentSince);
            if ($recentDraft && in_array($draft->gallery_status, ['queued', 'searching', 'running', 'processing'], true)) {
                $draftIds[] = $draft->id;
            } else {
                foreach (['draft-gallery-retrain', 'draft-gallery-restage', 'draft-gallery-continue', 'draft-gallery-topup', 'draft-photo-actions', 'draft-fields', 'draft-enrich', 'draft-upload'] as $key) {
                    if (Cache::has("{$key}:{$draft->id}:queued")) {
                        $draftIds[] = $draft->id;
                        break;
                    }
                }
            }
            if (count($draftIds) === 10) {
                break;
            }
        }
        if ($draftIds !== []) {
            $reasons[] = 'Черновики заняты поиском или редактированием: #'.implode(', #', $draftIds).'. Дождитесь завершения активного шага.';
        }

        return $reasons;
    }

    private function signature(array $plan): string
    {
        $key = (string) config('app.key');
        $this->assert($key !== '', 'configuration', 'Для подтверждения очистки нужен настроенный APP_KEY.');

        return hash_hmac('sha256', json_encode($plan, JSON_THROW_ON_ERROR), $key);
    }

    private function assert(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
