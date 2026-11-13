<?php

namespace App\Jobs;

use App\Ai\Tools\EditProductDraft;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Catalog\CategoryStandard;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * «🔎 Доискать» under a draft's form report (decision of 2026-11-13): one targeted search on the
 * web for the fields not found, through the same path as «find it» in the operator's words
 * (module 72); what is found is written with its page and the card comes back.
 */
class FindDraftFormFields implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param  list<string>  $keys  the fields to look for; empty for every field not found */
    public function __construct(public int $draftId, public int $updateId, public array $keys = [])
    {
        $this->onQueue('assistant');
    }

    public function handle(TelegramClient $telegram, CategoryStandard $standard): void
    {
        $draft = ProductDraft::query()->find($this->draftId);
        $update = TelegramUpdate::query()->find($this->updateId);
        if ($draft === null || $update === null || $draft->status !== 'pending_review') {
            return;
        }
        $fields = array_values(array_filter($standard->statuses($draft), fn (array $field): bool => $field['status'] === 'missing'
            && ($this->keys === [] || in_array($field['key'], $this->keys, true))));
        if ($fields === []) {
            $telegram->sendMessage((string) $update->chat_id, 'Искать нечего: эти поля уже заполнены.');

            return;
        }
        $wanted = implode(', ', array_map(fn (array $field): string => $field['name'].' ('.$field['key']
            .($field['variants'] !== [] ? ', one of: '.implode(' / ', $field['variants']) : '').')', $fields));
        $telegram->sendMessage((string) $update->chat_id, '🔎 Ищу для черновика #'.$draft->id.': '.implode(', ', array_column($fields, 'name')).'. Это один поиск, до пары минут.');
        try {
            $result = json_decode((string) (new EditProductDraft($update))->handle(new Request(['draft_id' => $draft->id, 'wanted' => $wanted])), true) ?: [];
        } catch (Throwable $exception) {
            report($exception);
            $result = ['ok' => false, 'error' => $exception->getMessage()];
        }
        if (($result['ok'] ?? false) !== true) {
            $telegram->sendMessage((string) $update->chat_id, '🔎 Поиск не удался: '.mb_substr((string) ($result['error'] ?? 'причина неизвестна'), 0, 300)
                .'. Значение можно вписать кнопкой «✍️ Вписать» в меню формы.');

            return;
        }
        if (($result['status'] ?? null) === 'nothing_found') {
            $telegram->sendMessage((string) $update->chat_id, '🔎 Не нашёл: '.implode(', ', array_column($fields, 'name'))
                .(filled($result['search_summary'] ?? null) ? '. '.mb_substr((string) $result['search_summary'], 0, 400) : '.')
                .' Значение можно вписать кнопкой «✍️ Вписать» в меню формы.');
        }
        // Found values come back with the card itself (the edit tool sends it).
    }
}
