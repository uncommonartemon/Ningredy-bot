<?php

namespace App\Services\Telegram;

use App\Ai\Agents\TelegramProductUploadAgent;
use App\Jobs\ProcessTelegramProductUpload;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\AiAttributeGroups;
use App\Services\Products\ProductCondition;
use App\Services\Products\ProductImageEncoder;
use App\Services\Vision\VisionCallContext;
use App\Services\Vision\VisionImagePreparer;
use App\Services\Vision\VisionRunner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** Operator material is a separate origin, never an internet gallery verdict. */
class TelegramProductUploadIntake
{
    public const MAX_PHOTOS = 10;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly VisionRunner $vision,
        private readonly VisionImagePreparer $images,
        private readonly ProductImageEncoder $encoder,
    ) {}

    public function queue(TelegramUpdate $update, ?string $description = null, ?int $draftId = null): void
    {
        if ($update->processed_at || $update->status === 'cancelled') {
            return;
        }
        $payload = $update->payload ?? [];
        $payload['_manual_product_upload'] = true;
        if ($description !== null) {
            $payload['_manual_product_description'] = mb_substr(trim($description), 0, 12000);
        }
        if ($draftId === null && filled(data_get($payload, 'message.media_group_id'))) {
            $draftId = ProductDraft::query()->whereIn('telegram_update_id', $this->groupedUpdates($update)->pluck('id'))
                ->where('status', 'pending_review')->value('id');
        }
        if ($draftId !== null) {
            $payload['_manual_product_draft_id'] = $draftId;
            Cache::put("draft-upload:{$draftId}:queued", true, 540);
        }
        $update->update(['payload' => $payload, 'status' => 'received']);
        ProcessTelegramProductUpload::dispatch($update->id)->delay(now()->addSeconds(3))->afterCommit();
    }

    public function clearUploadMarkerIfIdle(?int $draftId): void
    {
        if (! $draftId) {
            return;
        }
        $pending = TelegramUpdate::query()->whereNull('processed_at')->where('status', '!=', 'cancelled')
            ->where('payload->_manual_product_draft_id', $draftId)->exists();
        if (! $pending) {
            Cache::forget("draft-upload:{$draftId}:queued");
        }
    }

    /** @return Collection<int, TelegramUpdate> */
    public function groupedUpdates(TelegramUpdate $update): Collection
    {
        $group = data_get($update->payload, 'message.media_group_id');
        if (! is_string($group) || $group === '') {
            return collect([$update]);
        }

        return TelegramUpdate::query()->where('chat_id', $update->chat_id)
            ->where('telegram_user_id', $update->telegram_user_id)
            ->where('payload->message->media_group_id', $group)
            ->orderBy('message_id')->orderBy('id')->get();
    }

    public function groupKey(TelegramUpdate $update): string
    {
        return hash('sha256', implode('|', [$update->chat_id, $update->telegram_user_id,
            data_get($update->payload, 'message.media_group_id', 'update-'.$update->id)]));
    }

    /** @param Collection<int, TelegramUpdate> $updates */
    public function createFromUpdates(Collection $updates): ?ProductDraft
    {
        $owner = $updates->first();
        if (! $owner instanceof TelegramUpdate || $owner->status === 'cancelled') {
            return null;
        }
        $updates = $updates->filter(fn (TelegramUpdate $item): bool => $item->chat_id === $owner->chat_id
            && $item->telegram_user_id === $owner->telegram_user_id && $item->status !== 'cancelled')->values();
        $existing = ProductDraft::query()->whereIn('telegram_update_id', $updates->pluck('id'))->first();
        $targetIds = $updates->map(fn (TelegramUpdate $item) => data_get($item->payload, '_manual_product_draft_id'))
            ->filter()->unique()->values();
        throw_if($targetIds->count() > 1, RuntimeException::class, 'Альбом относится к разным черновикам.');
        $targetId = $targetIds->first();
        $target = $targetId ? ProductDraft::query()->with('telegramUpdate')->find($targetId) : null;
        throw_if($targetId && ! $target, RuntimeException::class, 'Черновик больше не существует.');
        if ($existing && ! $target) {
            return $existing;
        }
        if ($target && ($target->status !== 'pending_review'
            || $target->requested_by_telegram_user_id !== $owner->telegram_user_id
            || $target->telegramUpdate?->chat_id !== $owner->chat_id
            || ! data_get($target->telegramUpdate?->payload, '_manual_product_upload'))) {
            throw new RuntimeException('Этот черновик недоступен для загрузки фотографий.');
        }
        if ($target && $updates->every(fn (TelegramUpdate $item): bool => $item->processed_at !== null)) {
            return $target->fresh(['telegramUpdate', 'media']);
        }
        $targetFingerprint = $target ? $this->fingerprint($target) : null;

        $description = $updates->map(fn (TelegramUpdate $item): string => trim((string)
            (data_get($item->payload, '_manual_product_description')
                ?? data_get($item->payload, 'message.caption') ?? $item->text)))
            ->filter()->unique()->implode("\n");
        if ($target) {
            $description = trim((string) data_get($target->telegramUpdate?->payload,
                '_manual_product_intake.original_description')."\n".$description);
        }
        $incoming = $this->downloadFiles($updates);
        // Archive records describe origin; catalog snapshots and removed uploads
        // are not necessarily live gallery files. Append only to the current set.
        $files = [...($target ? data_get($target->manualIntake(), 'uploaded_photos', []) : []), ...$incoming];
        $existingFrames = $target ? $target->media()->orderBy('sort_order')->orderBy('id')->get() : collect();
        $existingContext = $existingFrames->take(self::MAX_PHOTOS)->map(fn ($frame): array => [
            'disk' => $frame->disk, 'path' => $frame->path, 'checksum' => $frame->checksum,
            'extension' => pathinfo($frame->path, PATHINFO_EXTENSION),
        ]);
        $incomingValid = collect($incoming)->filter(fn (array $file): bool => empty($file['error'])
            && isset($file['disk'], $file['path'], $file['checksum'], $file['extension']))->unique('checksum')
            ->reject(fn (array $file): bool => $existingFrames->contains('checksum', $file['checksum']))->values();
        $valid = $incomingValid->take(max(0, self::MAX_PHOTOS - $existingFrames->count()))->values();
        $context = $existingContext->concat($valid)->values();
        $attachments = $context->map(fn (array $file, int $index) => $this->images->fromBytes(
            Storage::disk($file['disk'])->get($file['path']), 'upload-'.($index + 1).'.'.$file['extension']))->all();
        $categories = Category::query()->where('is_active', true)->get(['slug', 'name', 'product_type_affinity'])->toArray();
        $currentCard = $target?->only(['title', 'brand', 'model', 'product_type', 'category', 'color', 'condition',
            'description', 'specifications']);
        $observedDescription = $description.($target ? "\nCurrent operator-confirmed draft:\n"
            .json_encode($currentCard, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : '');
        $prompt = json_encode(['description' => mb_substr($observedDescription, 0, 16000),
            'operation' => $target ? 'append_photos_only_preserve_current_card' : 'create_manual_draft',
            'current_draft' => $currentCard,
            'existing_gallery_context_count' => $existingContext->count(),
            'active_categories' => $categories, 'attached_photo_count' => count($attachments)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $data = $this->vision->run('product_upload', TelegramProductUploadAgent::make(), $prompt, $attachments,
            new VisionCallContext($owner->id, timeoutSeconds: 120, useSearchBudget: false),
            fn (array $data): array => $this->validate($data, $observedDescription, $context->count()));
        if ($updates->contains(fn (TelegramUpdate $item): bool => $item->refresh()->status === 'cancelled')) {
            return null;
        }
        if ($target) {
            // Adding photos is not permission to overwrite a card edited by
            // the operator. Only photo applicability is newly assessed.
            foreach (['title', 'brand', 'model', 'product_type', 'category', 'color', 'condition', 'description'] as $field) {
                $data[$field] = $target->{$field};
            }
            $data['title_cs'] = data_get($target->translations, 'cs.title');
            $data['description_cs'] = data_get($target->translations, 'cs.description');
            $data['specifications'] = collect($target->specifications ?? [])->map(fn (array $spec): array => [
                'key' => $spec['key'], 'name' => $spec['name'], 'value' => $spec['value'],
                'group' => AiAttributeGroups::normalize($spec['group'] ?? null),
                'name_cs' => data_get($target->translations, 'cs.specifications.'.$spec['key'].'.name'),
                'value_cs' => data_get($target->translations, 'cs.specifications.'.$spec['key'].'.value'),
                'source' => 'description', 'quote' => $spec['source_excerpt'] ?? $spec['value'], 'photo_index' => null,
            ])->all();
            $data['commercial'] = data_get($target->telegramUpdate?->payload, '_manual_product_intake.commercial', $data['commercial']);
            $data['commercial_quotes'] = data_get($target->telegramUpdate?->payload, '_manual_product_intake.commercial_quotes', []);
            $data['conflicts'] = array_values(array_unique([...data_get($target->telegramUpdate?->payload,
                '_manual_product_intake.conflicts', []), ...$data['conflicts']]));
        }
        $photos = collect($data['photos'])->keyBy('index');
        $accepted = $valid->filter(fn (array $file, int $index): bool => data_get($photos->get($existingContext->count() + $index + 1), 'kind') === 'product');
        $missing = [];
        if (trim((string) $data['title']) === '' || ($target && $data['title'] === 'Uploaded product'
            && in_array('title', data_get($target->manualIntake(), 'missing_fields', []), true))) {
            $missing[] = 'title';
        }
        if (! Category::query()->where('slug', $data['category'])->where('is_active', true)->exists()) {
            $missing[] = 'category';
        }
        if (! in_array($data['condition'], ProductCondition::VALUES, true)) {
            $missing[] = 'condition';
        }
        if ($accepted->isEmpty() && $existingFrames->isEmpty()) {
            $missing[] = 'product_photo';
        }
        $metadata = [...($target ? $target->manualIntake() : []),
            'status' => $missing === [] && $data['conflicts'] === [] ? 'ready' : 'needs_input',
            'missing_fields' => $missing, 'suggested_fields' => $data['missing_fields'], 'conflicts' => $data['conflicts'],
            'original_description' => $description, 'telegram_update_ids' => array_values(array_unique([
                ...($target ? data_get($target->telegramUpdate?->payload, '_manual_product_intake.telegram_update_ids', []) : []),
                ...$updates->pluck('id')->all(),
            ])),
            'uploaded_photos' => $files, 'photo_verdicts' => $data['photos'],
            'fact_provenance' => $target ? data_get($target->manualIntake(), 'fact_provenance', []) : $data['specifications'],
            'commercial' => $data['commercial'],
            'commercial_quotes' => $data['commercial_quotes'],
            'photo_limit' => self::MAX_PHOTOS, 'extra_photos' => max(0, $existingFrames->count() + $incomingValid->count() - self::MAX_PHOTOS),
            'warnings' => collect($files)->pluck('error')->filter()->values()->all()];

        return DB::transaction(function () use ($owner, $updates, $target, $targetFingerprint, $data, $metadata, $accepted, $existingContext): ProductDraft {
            // Recheck at commit time: cancelling or rejecting during the AI call cannot mutate a card.
            $fresh = TelegramUpdate::query()->lockForUpdate()->findOrFail($owner->id);
            throw_if($fresh->status === 'cancelled', RuntimeException::class, 'Загрузка отменена.');
            $draft = $target ? ProductDraft::query()->lockForUpdate()->findOrFail($target->id) : new ProductDraft;
            throw_if($target && $draft->status !== 'pending_review', RuntimeException::class, 'Черновик уже обработан.');
            $draftOwner = $target ? TelegramUpdate::query()->lockForUpdate()->findOrFail($draft->telegram_update_id) : $fresh;
            $draft->setRelation('telegramUpdate', $draftOwner);
            throw_if($target && $this->fingerprint($draft) !== $targetFingerprint,
                RuntimeException::class, 'Черновик изменился во время разбора фото. Повторите загрузку.');
            $payload = $draftOwner->payload ?? [];
            $payload['_manual_product_upload'] = true;
            $payload['_manual_product_intake'] = $metadata;
            $draftOwner->update(['payload' => $payload]);
            $specifications = $target ? $target->specifications : collect($data['specifications'])->map(fn (array $spec): array => [
                'key' => $spec['key'], 'name' => $spec['name'], 'value' => $spec['value'],
                'group' => AiAttributeGroups::normalize($spec['group'] ?? null),
                'source_url' => null, 'source_excerpt' => $spec['quote'],
            ])->all();
            $translations = $target ? $target->translations : ['cs' => ['title' => $data['title_cs'], 'description' => $data['description_cs'],
                'specifications' => collect($data['specifications'])->mapWithKeys(fn (array $spec): array => [
                    $spec['key'] => ['name' => $spec['name_cs'], 'value' => $spec['value_cs']],
                ])->all()]];
            $draft->fill(['telegram_update_id' => $draftOwner->id,
                'ai_run_id' => AiRun::query()->where('telegram_update_id', $owner->id)->latest('id')->value('id'),
                'status' => 'pending_review', 'requested_by_telegram_user_id' => $owner->telegram_user_id,
                'title' => $data['title'] ?: 'Uploaded product', 'brand' => $data['brand'], 'model' => $data['model'],
                'product_type' => $data['product_type'], 'category' => $data['category'], 'color' => $data['color'],
                'condition' => $data['condition'] ?? '', 'description' => $data['description'], 'translations' => $translations,
                'specifications' => $specifications, 'specification_evidence' => $target?->specification_evidence ?? [],
                'sources' => $target?->sources ?? [],
                'primary_source_url' => $target?->primary_source_url, 'official_source_url' => $target?->official_source_url, 'image_urls' => [],
                'gallery_status' => $accepted->isEmpty() && $draft->media()->doesntExist() ? 'not_found' : 'ready',
                'gallery_search_stop_reason' => null, 'gallery_confirmed_sufficient' => $accepted->isNotEmpty() || $draft->media()->exists(),
                'research_notes' => $target?->research_notes ?? 'Prepared from operator-supplied material; no internet verification.',
                'gallery_notes' => $target?->gallery_notes ?? 'Operator-uploaded photos; no manufacturer or website provenance claimed.',
                'confidence' => $target?->confidence ?? 0, 'images_staged_at' => now()])->save();
            foreach ($accepted as $index => $file) {
                if ($draft->media()->where('checksum', $file['checksum'])->exists()) {
                    continue;
                }
                $bytes = Storage::disk($file['disk'])->get($file['path']);
                $decoded = @imagecreatefromstring($bytes);
                throw_if($decoded === false, RuntimeException::class, 'Фотография не декодируется.');
                try {
                    $encoded = $this->encoder->toWebp($decoded);
                } finally {
                    imagedestroy($decoded);
                }
                $position = $draft->media()->exists() ? $draft->media()->max('sort_order') + 1 : 0;
                $path = 'drafts/'.$draft->id.'/manual-'.substr($file['checksum'], 0, 16).'.webp';
                throw_unless(Storage::disk('public')->put($path, $encoded['bytes']), RuntimeException::class, 'Не удалось сохранить фото.');
                $draft->media()->create(['disk' => 'public', 'path' => $path, 'source_url' => null,
                    'role' => $position === 0 ? 'primary' : 'detail', 'mime_type' => 'image/webp',
                    'width' => $encoded['width'], 'height' => $encoded['height'], 'file_size' => strlen($encoded['bytes']),
                    'checksum' => $file['checksum'], 'verification_status' => 'manual', 'verification_score' => null,
                    'verification_model' => null, 'verification_notes' => json_encode([
                        'origin' => 'telegram_upload', 'telegram_update_id' => $file['telegram_update_id'],
                        'original_path' => $file['path'], 'content_check' => 'product_visible',
                        'reason' => data_get(collect($data['photos'])->keyBy('index')->get($existingContext->count() + $index + 1), 'reason'),
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'sort_order' => $position, 'is_primary' => $position === 0, 'gallery_traversal_complete' => false]);
            }
            foreach ($updates as $item) {
                $item->update(['status' => 'completed', 'processed_at' => now(), 'error' => null]);
            }

            return $draft->fresh(['telegramUpdate', 'media']);
        });
    }

    private function downloadFiles(Collection $updates): array
    {
        $files = [];
        foreach ($updates as $update) {
            $cached = data_get($update->payload, '_manual_product_files');
            if (is_array($cached)) {
                $files = [...$files, ...$cached];

                continue;
            }
            $sizes = data_get($update->payload, 'message.photo', []);
            $file = is_array($sizes) && $sizes !== [] ? end($sizes) : data_get($update->payload, 'message.document');
            if (! is_array($file)) {
                continue;
            }
            $record = ['telegram_update_id' => $update->id, 'message_id' => $update->message_id,
                'file_id' => $file['file_id'] ?? null, 'checksum' => null];
            try {
                $maximum = (int) config('services.telegram_photo.max_bytes', 10485760);
                throw_if(empty($record['file_id']) || (int) ($file['file_size'] ?? 0) > $maximum,
                    RuntimeException::class, 'Telegram-файл отсутствует или больше разрешённого размера.');
                $remote = $this->telegram->getFile($record['file_id']);
                $remotePath = (string) data_get($remote, 'result.file_path');
                throw_if($remotePath === '', RuntimeException::class, 'Telegram не передал путь файла.');
                $bytes = $this->telegram->downloadFile($remotePath);
                throw_if(strlen($bytes) > $maximum, RuntimeException::class, 'Фото больше разрешённого размера.');
                $info = @getimagesizefromstring($bytes);
                throw_unless($info && $this->encoder->isSafeToDecode($info[0], $info[1])
                    && in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true),
                    RuntimeException::class, 'Файл не является безопасной фотографией.');
                $decoded = @imagecreatefromstring($bytes);
                throw_if($decoded === false, RuntimeException::class, 'Фото не декодируется.');
                imagedestroy($decoded);
                $extension = match ($info['mime']) {
                    'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => 'jpg'
                };
                $checksum = hash('sha256', $bytes);
                $path = 'telegram-uploads/'.$update->id.'/'.$checksum.'.'.$extension;
                throw_unless(Storage::disk('public')->put($path, $bytes), RuntimeException::class, 'Не удалось сохранить оригинал фото.');
                $record = [...$record, 'checksum' => $checksum, 'disk' => 'public', 'path' => $path,
                    'extension' => $extension, 'width' => $info[0], 'height' => $info[1], 'file_size' => strlen($bytes)];
            } catch (\Throwable $exception) {
                $record['error'] = mb_substr($exception->getMessage(), 0, 1000);
            }
            $payload = $update->payload ?? [];
            $payload['_manual_product_files'] = [$record];
            $payload['_manual_product_upload'] = true;
            $update->update(['payload' => $payload]);
            $files[] = $record;
        }

        return $files;
    }

    private function validate(array $data, string $description, int $photoCount): array
    {
        Validator::make($data, [
            'title' => ['present', 'nullable', 'string', 'max:255'],
            'brand' => ['present', 'nullable', 'string', 'max:255'], 'model' => ['present', 'nullable', 'string', 'max:255'],
            'product_type' => ['required', 'in:laptop,desktop,component,other'],
            'category' => ['present', 'nullable', 'string'], 'color' => ['present', 'nullable', 'string'],
            'condition' => ['present', 'nullable', 'in:'.implode(',', ProductCondition::VALUES)],
            'description' => ['present', 'nullable', 'string', 'max:5000'],
            'title_cs' => ['present', 'nullable', 'string'], 'description_cs' => ['present', 'nullable', 'string'],
            'specifications' => ['present', 'array', 'max:100'],
            'specifications.*.key' => ['required', 'regex:/^[a-z0-9_]+$/', 'distinct', 'max:100'],
            'specifications.*.name' => ['required', 'string', 'max:255'], 'specifications.*.value' => ['required', 'string', 'max:2000'],
            'specifications.*.source' => ['required', 'in:description,photo_label'],
            'specifications.*.quote' => ['required', 'string', 'max:800'],
            'specifications.*.name_cs' => ['present', 'nullable', 'string'], 'specifications.*.value_cs' => ['present', 'nullable', 'string'],
            'specifications.*.photo_index' => ['present', 'nullable', 'integer', 'min:1', 'max:'.max(1, $photoCount)],
            'specifications.*.group' => ['sometimes', 'nullable', 'array:id,name,name_cs'],
            'specifications.*.group.id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'specifications.*.group.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'specifications.*.group.name_cs' => ['sometimes', 'nullable', 'string', 'max:255'],
            'photos' => ['present', 'array', 'size:'.$photoCount],
            'photos.*.index' => ['required', 'integer', 'distinct', 'min:1', 'max:'.max(1, $photoCount)],
            'photos.*.kind' => ['required', 'in:product,label,unrelated,uncertain'], 'photos.*.reason' => ['required', 'string', 'max:800'],
            'missing_fields' => ['present', 'array'], 'missing_fields.*' => ['string'],
            'conflicts' => ['present', 'array'], 'conflicts.*' => ['string'],
            'commercial' => ['required', 'array'],
            'commercial.price_amount' => ['present', 'nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'commercial.currency' => ['present', 'nullable', 'regex:/^[A-Z]{3}$/'],
            'commercial.quantity' => ['present', 'nullable', 'integer', 'min:0'],
            'commercial.warranty_months' => ['present', 'nullable', 'integer', 'min:0'],
            'commercial_quotes' => ['present', 'array', 'max:4'],
            'commercial_quotes.*.field' => ['required', 'in:price_amount,currency,quantity,warranty_months', 'distinct'],
            'commercial_quotes.*.quote' => ['required', 'string', 'max:800'],
        ])->validate();
        foreach ($data['specifications'] as $spec) {
            throw_if($spec['source'] === 'description' && ! str_contains($description, $spec['quote']),
                RuntimeException::class, 'Агент указал характеристику без цитаты в описании.');
            throw_if($spec['source'] === 'photo_label' && ($photoCount === 0 || $spec['photo_index'] === null),
                RuntimeException::class, 'Агент указал характеристику без фотографии-источника.');
        }
        $quotes = collect($data['commercial_quotes'])->keyBy('field');
        foreach ($data['commercial'] as $field => $value) {
            if ($value !== null) {
                $quote = data_get($quotes->get($field), 'quote', '');
                throw_if($quote === '' || ! str_contains($description, $quote), RuntimeException::class,
                    'Агент указал коммерческие условия без цитаты оператора.');
            }
        }

        return $data;
    }

    private function fingerprint(ProductDraft $draft): string
    {
        return hash('sha256', json_encode([$draft->only(['title', 'brand', 'model', 'category', 'condition',
            'color', 'description', 'translations', 'specifications']), $draft->manualIntake(),
            $draft->media()->orderBy('id')->get(['id', 'checksum', 'path', 'verification_status', 'sort_order', 'is_primary'])->toArray()],
            JSON_THROW_ON_ERROR));
    }
}
