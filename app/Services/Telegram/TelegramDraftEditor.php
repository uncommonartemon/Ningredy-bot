<?php

namespace App\Services\Telegram;

use App\Ai\Agents\TelegramDraftEditAgent;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Products\AiAttributeGroups;
use App\Services\Products\ProductCondition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Editing creates a signed preview. Only its separately confirmed application changes a draft. */
class TelegramDraftEditor
{
    private const FIELDS = ['title', 'brand', 'model', 'color', 'condition', 'description', 'category', 'product_type', 'specifications'];

    private const COMMERCIAL_FIELDS = ['price_amount', 'currency', 'quantity', 'warranty_months'];

    private const LABELS = [
        'title' => 'Название', 'brand' => 'Бренд', 'model' => 'Модель', 'color' => 'Цвет',
        'condition' => 'Состояние', 'description' => 'Описание', 'category' => 'Категория',
        'product_type' => 'Тип товара', 'price_amount' => 'Цена', 'currency' => 'Валюта',
        'quantity' => 'Количество', 'warranty_months' => 'Гарантия, мес.',
    ];

    public function __construct(
        private readonly DraftAccess $access,
        private readonly AiSettings $settings,
        private readonly ProductSearchCancellation $cancellation,
        private readonly OpenAiHeavyOperationGate $gate,
    ) {}

    public function propose(ProductDraft $draft, TelegramUpdate $update, string $text): array
    {
        $draft->refresh();
        $this->assertContext($draft, $update);
        $text = trim($text);
        throw_if($text === '' || mb_strlen($text) > 15000, RuntimeException::class, 'Напишите изменения, до 15 000 символов.');
        $fingerprint = $this->fingerprint($draft);
        $run = null;

        try {
            $data = $this->simpleChange($text);
            if ($data === null) {
                $provider = $this->settings->providerFor('server_assistant');
                $model = $this->settings->modelFor('server_assistant');
                $prompt = json_encode([
                    'operator_message' => $text,
                    'current_draft' => $draft->only(self::FIELDS),
                    'commercial' => $draft->manualIntake()['commercial'] ?? [],
                    'active_categories' => Category::query()->where('is_active', true)->pluck('name', 'slug')->all(),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $run = AiRun::query()->create([
                    'telegram_update_id' => $update->id, 'provider' => $provider, 'model' => $model,
                    'status' => 'running', 'prompt' => $prompt, 'started_at' => now(),
                    'activity' => ['operation' => 'telegram_draft_edit', 'draft_id' => $draft->id],
                ]);
                $response = $this->gate->run($provider, 90, function () use ($draft, $update, $prompt, $provider, $model) {
                    $this->assertContext($draft->fresh(), $update);

                    return TelegramDraftEditAgent::make()->prompt($prompt, provider: $provider, model: $model, timeout: 90);
                });
                // Even a rejected answer consumed a real call. Record it before checking the result.
                $run->update(['invocation_id' => $response->invocationId, 'response' => $response->toArray(), 'usage' => $response->usage->toArray()]);
                $data = $response->toArray();
            }
            $patch = $this->patchFromChanges($draft, $text, $data);
            $draft->refresh();
            $this->assertContext($draft, $update);
            throw_unless(hash_equals($fingerprint, $this->fingerprint($draft)), RuntimeException::class, 'Черновик изменился во время подготовки правки. Откройте редактирование ещё раз.');
            $proposal = $this->proposalFor($draft, $update, $patch, (string) ($data['summary'] ?? ''), $run?->id);
            $run?->update(['status' => 'completed', 'completed_at' => now()]);

            return $proposal;
        } catch (Throwable $exception) {
            $run?->update([
                'status' => $exception instanceof ProductSearchCancelled ? 'cancelled' : 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000), 'completed_at' => now(),
            ]);

            throw $exception;
        }
    }

    /** Also accepts a server-prepared, cited enrichment patch without another parsing call. */
    public function proposalFor(ProductDraft $draft, TelegramUpdate $update, array $patch, string $summary, ?int $runId = null): array
    {
        $draft->refresh();
        $this->assertContext($draft, $update);
        $patch = $this->validatePatch($patch);
        if (isset($patch['fields']['specifications'])) {
            $current = collect($draft->specifications ?? [])->keyBy('key');
            foreach ($patch['fields']['specifications'] as &$specification) {
                $previous = $current->get($specification['key']);
                if (! array_key_exists('group', $specification) && is_array($previous) && array_key_exists('group', $previous)) {
                    $specification['group'] = AiAttributeGroups::normalize($previous['group']);
                }
            }
            unset($specification);
        }
        throw_if($patch['commercial'] !== [] && ! $draft->isManualUpload(), RuntimeException::class,
            'Коммерческие поля пока редактируются через черновик загруженного товара.');
        $lines = $this->previewLines($draft, $patch);
        throw_if($lines === [], RuntimeException::class, $summary !== '' ? $summary : 'В сообщении нет изменений для этого черновика.');
        $proposal = [
            'draft_id' => $draft->id, 'generation' => $draft->telegram_update_id,
            'chat_id' => (string) $update->chat_id, 'user_id' => (string) $update->telegram_user_id,
            'fingerprint' => $this->fingerprint($draft), 'patch' => $patch,
            'summary' => mb_substr($summary, 0, 500), 'preview' => implode("\n\n", $lines),
            'preview_messages' => $this->chunks($lines), 'run_id' => $runId,
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ];
        $proposal['signature'] = $this->signature($proposal);

        return $proposal;
    }

    public function apply(ProductDraft $draft, TelegramUpdate $update, array $proposal): ProductDraft
    {
        $signature = (string) ($proposal['signature'] ?? '');
        unset($proposal['signature']);
        throw_unless($signature !== '' && hash_equals($signature, $this->signature($proposal)), RuntimeException::class, 'Предложение правки недействительно. Откройте редактирование ещё раз.');

        return DB::transaction(function () use ($draft, $update, $proposal): ProductDraft {
            $draft = ProductDraft::query()->lockForUpdate()->findOrFail($draft->id);
            $origin = TelegramUpdate::query()->lockForUpdate()->find($draft->telegram_update_id);
            $draft->setRelation('telegramUpdate', $origin);
            $this->assertContext($draft, $update);
            throw_unless((int) ($proposal['draft_id'] ?? 0) === $draft->id
                && (int) ($proposal['generation'] ?? 0) === (int) $draft->telegram_update_id
                && ($proposal['chat_id'] ?? '') === (string) $update->chat_id
                && ($proposal['user_id'] ?? '') === (string) $update->telegram_user_id
                && (int) ($proposal['expires_at'] ?? 0) >= now()->getTimestamp()
                && hash_equals((string) ($proposal['fingerprint'] ?? ''), $this->fingerprint($draft)),
                RuntimeException::class, 'Черновик или фотографии изменились, либо предложение устарело. Подготовьте правку ещё раз.');
            $patch = $this->validatePatch($proposal['patch']);
            throw_if(($patch['commercial'] ?? []) !== [] && ! $draft->isManualUpload(), RuntimeException::class,
                'Коммерческие поля пока редактируются через черновик загруженного товара.');

            $fields = $patch['fields'];
            // Invalidate only translations that belonged to edited values.
            $translations = $this->updatedTranslations($draft, $fields);
            if ($translations !== $draft->translations) {
                $fields['translations'] = $translations;
            }
            if (! $draft->isManualUpload() && $fields !== []) {
                $fields['specifications_reconciled_source_url'] = null;
                $fields['specifications_reconciliation_attempts'] = 0;
            }
            $draft->fill($fields)->save();
            if ($draft->isManualUpload() && $origin) {
                $payload = $origin->payload ?? [];
                $intake = $payload['_manual_product_intake'] ?? [];
                $intake['commercial'] = array_replace($intake['commercial'] ?? [], $patch['commercial']);
                $intake['edits'][] = ['telegram_update_id' => $update->id, 'at' => now()->toIso8601String(),
                    'fields' => array_keys($fields), 'commercial_fields' => array_keys($patch['commercial']), 'ai_run_id' => $proposal['run_id'] ?? null];
                $intake['missing_fields'] = $this->missingFields($draft);
                $intake['status'] = $intake['missing_fields'] === [] && empty($intake['conflicts']) ? 'ready' : 'needs_input';
                $payload['_manual_product_intake'] = $intake;
                $origin->update(['payload' => $payload]);
            }

            return $draft->fresh(['telegramUpdate', 'media']);
        });
    }

    public function fingerprint(ProductDraft $draft): string
    {
        return hash('sha256', json_encode([
            'status' => $draft->status, 'generation' => $draft->telegram_update_id,
            'fields' => $draft->only([...self::FIELDS, 'translations', 'specification_evidence', 'primary_source_url', 'specifications_reconciled_source_url']),
            'intake' => $draft->manualIntake(),
            'media' => $draft->media()->orderBy('id')->get(['id', 'path', 'checksum', 'verification_status', 'sort_order', 'is_primary'])->toArray(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function assertContext(ProductDraft $draft, TelegramUpdate $update): void
    {
        $this->cancellation->throwIfCancelled($update->id);
        $this->cancellation->throwIfCancelled($draft->telegram_update_id);
        throw_unless($draft->status === 'pending_review' && $this->access->belongsToContext($draft, $update), RuntimeException::class,
            'Этот черновик уже обработан или недоступен в этом чате.');
    }

    private function patchFromChanges(ProductDraft $draft, string $text, array $data): array
    {
        Validator::make($data, [
            'summary' => 'required|string|max:500', 'changes' => 'present|array|max:40',
            'changes.*.field' => 'required|string|in:'.implode(',', TelegramDraftEditAgent::FIELDS),
            'changes.*.name' => 'present|nullable|string|max:255', 'changes.*.value' => 'present|nullable|string|max:5000',
            'changes.*.evidence_quote' => 'required|string|max:2000',
            'changes.*.group' => 'sometimes|nullable|array:id,name,name_cs',
            'changes.*.group.id' => 'sometimes|nullable|integer|min:1',
            'changes.*.group.name' => 'sometimes|nullable|string|max:255',
            'changes.*.group.name_cs' => 'sometimes|nullable|string|max:255',
        ])->validate();
        $fields = $commercial = [];
        $specifications = $draft->specifications ?? [];
        $seen = [];
        foreach ($data['changes'] as $change) {
            throw_unless(str_contains($text, $change['evidence_quote']), RuntimeException::class, 'Агент предложил изменение без цитаты из вашего сообщения.');
            $field = $change['field'];
            $value = $change['value'];
            $identity = $field.':'.mb_strtolower(trim((string) ($change['name'] ?? '')));
            throw_if(isset($seen[$identity]), RuntimeException::class, 'Агент предложил противоречивые изменения одного поля.');
            $seen[$identity] = true;
            if ($field === 'specification') {
                $name = trim((string) $change['name']);
                throw_if($name === '', RuntimeException::class, 'Для характеристики нужно название.');
                $existing = null;
                foreach ($specifications as $index => $specification) {
                    if (is_array($specification) && mb_strtolower((string) ($specification['name'] ?? '')) === mb_strtolower($name)) {
                        $existing = $index;
                        break;
                    }
                }
                if ($value === null) {
                    if ($existing !== null) {
                        unset($specifications[$existing]);
                    }
                } else {
                    $item = ['key' => $existing !== null ? ($specifications[$existing]['key'] ?? Str::slug($name, '_')) : Str::slug($name, '_'),
                        'name' => $name, 'value' => $value, 'source_url' => null, 'source_excerpt' => $change['evidence_quote']];
                    if (array_key_exists('group', $change)) {
                        $item['group'] = AiAttributeGroups::normalize($change['group']);
                    } elseif ($existing !== null && array_key_exists('group', $specifications[$existing])) {
                        $item['group'] = AiAttributeGroups::normalize($specifications[$existing]['group']);
                    }
                    if ($existing !== null) {
                        $specifications[$existing] = $item;
                    } else {
                        $specifications[] = $item;
                    }
                }
                $fields['specifications'] = array_values($specifications);
            } elseif (in_array($field, self::COMMERCIAL_FIELDS, true)) {
                $commercial[$field] = $value;
            } else {
                $fields[$field] = $value;
            }
        }

        return ['fields' => $fields, 'commercial' => $commercial];
    }

    private function validatePatch(array $patch): array
    {
        throw_if(array_diff(array_keys($patch), ['fields', 'commercial']) !== [], RuntimeException::class, 'Недопустимое поле изменения.');
        $fields = $patch['fields'] ?? [];
        $commercial = $patch['commercial'] ?? [];
        throw_unless(is_array($fields) && is_array($commercial), RuntimeException::class, 'Неверный формат изменения.');
        throw_if(array_diff(array_keys($fields), self::FIELDS) !== [] || array_diff(array_keys($commercial), self::COMMERCIAL_FIELDS) !== [], RuntimeException::class, 'Недопустимое поле изменения.');
        Validator::make($fields, [
            'title' => 'sometimes|required|string|max:255', 'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255', 'color' => 'nullable|string|max:255',
            'condition' => 'sometimes|required|in:'.implode(',', ProductCondition::VALUES),
            'description' => 'nullable|string|max:5000', 'category' => 'sometimes|required|string|max:255',
            'product_type' => 'sometimes|required|in:laptop,desktop,component,other',
            'specifications' => 'sometimes|array|max:100', 'specifications.*.key' => 'required|string|max:100',
            'specifications.*.name' => 'required|string|max:255', 'specifications.*.value' => 'required|string|max:2000',
            'specifications.*.source_url' => 'nullable|url|max:2048', 'specifications.*.source_excerpt' => 'nullable|string|max:2000',
            'specifications.*.group' => 'sometimes|nullable|array:id,name,name_cs',
            'specifications.*.group.id' => 'sometimes|nullable|integer|min:1',
            'specifications.*.group.name' => 'sometimes|nullable|string|max:255',
            'specifications.*.group.name_cs' => 'sometimes|nullable|string|max:255',
        ])->validate();
        if (isset($fields['specifications'])) {
            throw_unless(array_is_list($fields['specifications'])
                && count(array_unique(array_column($fields['specifications'], 'key'))) === count($fields['specifications']),
                RuntimeException::class, 'Характеристики должны иметь разные ключи и передаваться списком.');
            foreach ($fields['specifications'] as &$specification) {
                if (array_key_exists('group', $specification)) {
                    $specification['group'] = AiAttributeGroups::normalize($specification['group']);
                }
            }
            unset($specification);
        }
        if (isset($fields['category'])) {
            throw_unless(Category::query()->where('slug', $fields['category'])->where('is_active', true)->exists(), RuntimeException::class, 'Выберите существующую активную категорию.');
        }
        foreach ($commercial as $field => $value) {
            if ($value === null) {
                throw_if(in_array($field, ['currency', 'quantity'], true), RuntimeException::class,
                    'Валюту и количество нельзя удалить. Укажите код валюты или количество 0.');

                continue;
            }
            $value = trim((string) $value);
            if ($field === 'currency') {
                $value = strtoupper($value);
                throw_unless(preg_match('/^[A-Z]{3}$/D', $value), RuntimeException::class, 'Валюта должна иметь трёхбуквенный код: например, CZK или EUR.');
            } elseif ($field === 'price_amount') {
                $value = str_replace(',', '.', $value);
                throw_unless(preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value), RuntimeException::class, 'Цена должна быть неотрицательным числом, максимум два знака после запятой.');
                $value = number_format((float) $value, 2, '.', '');
            } else {
                throw_unless(preg_match('/^\d{1,7}$/D', $value), RuntimeException::class, 'Количество и гарантия должны быть целыми неотрицательными числами.');
                $value = (int) $value;
                throw_if($field === 'warranty_months' && $value > 65535, RuntimeException::class, 'Срок гарантии слишком большой.');
            }
            $commercial[$field] = $value;
        }

        return ['fields' => $fields, 'commercial' => $commercial];
    }

    private function simpleChange(string $text): ?array
    {
        $aliases = ['название' => 'title', 'описание' => 'description', 'бренд' => 'brand', 'модель' => 'model',
            'цвет' => 'color', 'валюта' => 'currency', 'количество' => 'quantity', 'гарантия' => 'warranty_months'];
        if (preg_match('/^(название|описание|бренд|модель|цвет|валюта|количество|гарантия)\s*:\s*(.+)$/isu', $text, $match)) {
            return ['summary' => 'Правка из вашего сообщения.', 'changes' => [['field' => $aliases[mb_strtolower($match[1])],
                'name' => null, 'value' => trim($match[2]), 'evidence_quote' => mb_substr($text, 0, 2000)]]];
        }
        if (preg_match('/^цена\s*:?\s*(\d[\d\s]*(?:[.,]\d{1,2})?)\s*([A-Za-z]{3})?$/iu', $text, $match)) {
            $changes = [['field' => 'price_amount', 'name' => null, 'value' => preg_replace('/\s/u', '', $match[1]), 'evidence_quote' => $text]];
            if (! empty($match[2])) {
                $changes[] = ['field' => 'currency', 'name' => null, 'value' => strtoupper($match[2]), 'evidence_quote' => $text];
            }

            return ['summary' => 'Правка цены из вашего сообщения.', 'changes' => $changes];
        }

        return null;
    }

    private function previewLines(ProductDraft $draft, array $patch): array
    {
        $lines = [];
        foreach ($patch['fields'] as $field => $value) {
            if ($field === 'specifications') {
                $before = collect($draft->specifications ?? [])->keyBy('key');
                $after = collect($value)->keyBy('key');
                foreach ($before->keys()->merge($after->keys())->unique() as $key) {
                    $old = $before->get($key);
                    $new = $after->get($key);
                    if (($old['value'] ?? null) !== ($new['value'] ?? null) || ($old['name'] ?? null) !== ($new['name'] ?? null)) {
                        $lines[] = ($new['name'] ?? $old['name']).":\n".$this->display($old['value'] ?? null).' → '.$this->display($new['value'] ?? null);
                    }
                }
            } elseif ($draft->{$field} !== $value) {
                $lines[] = self::LABELS[$field].":\n".$this->display($draft->{$field}).' → '.$this->display($value);
            }
        }
        $commercial = $draft->manualIntake()['commercial'] ?? [];
        foreach ($patch['commercial'] as $field => $value) {
            if (($commercial[$field] ?? null) !== $value) {
                $lines[] = self::LABELS[$field].":\n".$this->display($commercial[$field] ?? null).' → '.$this->display($value);
            }
        }

        return $lines;
    }

    private function display(mixed $value): string
    {
        return $value === null || $value === '' ? 'не указано' : (string) $value;
    }

    private function chunks(array $lines): array
    {
        $chunks = [];
        $current = '';
        foreach ($lines as $line) {
            while (mb_strlen($line) > 3500) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks[] = mb_substr($line, 0, 3500);
                $line = mb_substr($line, 3500);
            }
            if (mb_strlen($current."\n\n".$line) > 3500) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= ($current !== '' ? "\n\n" : '').$line;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function missingFields(ProductDraft $draft): array
    {
        $missing = [];
        if (trim((string) $draft->title) === '') {
            $missing[] = 'title';
        }
        if (! Category::query()->where('slug', $draft->category)->where('is_active', true)->exists()) {
            $missing[] = 'category';
        }
        if (! in_array($draft->condition, ProductCondition::VALUES, true)) {
            $missing[] = 'condition';
        }
        if (! $draft->media()->whereIn('verification_status', ['manual', 'verified', 'source_verified'])->exists()) {
            $missing[] = 'product_photo';
        }

        return $missing;
    }

    private function updatedTranslations(ProductDraft $draft, array $fields): ?array
    {
        $translations = $draft->translations;
        if (! is_array($translations)) {
            return $translations;
        }
        $textChanged = collect(['title', 'description'])->contains(fn ($field) => array_key_exists($field, $fields) && $fields[$field] !== $draft->{$field});
        $changedKeys = [];
        if (isset($fields['specifications'])) {
            $before = collect($draft->specifications ?? [])->keyBy('key');
            $after = collect($fields['specifications'])->keyBy('key');
            foreach ($before->keys()->merge($after->keys())->unique() as $key) {
                if (($before->get($key)['value'] ?? null) !== ($after->get($key)['value'] ?? null)
                    || ($before->get($key)['name'] ?? null) !== ($after->get($key)['name'] ?? null)) {
                    $changedKeys[] = $key;
                }
            }
        }
        foreach ($translations as &$entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ($textChanged) {
                // Text translations are a title+description bundle in CatalogLocales.
                unset($entry['title'], $entry['description']);
            }
            if ($changedKeys !== [] && isset($entry['specifications']) && is_array($entry['specifications'])) {
                $entry['specifications'] = array_values(array_filter($entry['specifications'], fn ($item) => is_array($item) && ! in_array($item['key'] ?? null, $changedKeys, true)));
            }
        }
        unset($entry);

        return $translations;
    }

    private function signature(array $proposal): string
    {
        return hash_hmac('sha256', json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
