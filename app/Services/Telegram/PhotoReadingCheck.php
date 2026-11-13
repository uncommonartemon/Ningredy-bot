<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramMessage;
use App\Jobs\TranscribeTelegramPhoto;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use Illuminate\Support\Facades\Cache;

/**
 * The operator checks what the bot read on a photo before it becomes a search (decision of
 * 2026-11-13). A reader that is 99% sure can still read one character wrong (the colleague's
 * #333: a memory module's code read with two wrong characters, twenty minutes of search for a
 * part that does not exist, no photo). The reading is shown with four buttons: right, correct
 * it, read once more, cancel. Until the answer the request waits and nothing is searched.
 */
class PhotoReadingCheck
{
    /** Where the request keeps its reading: how many times the photo was read, the last text, a correction to apply. */
    public const string PAYLOAD_KEY = '_photo_reading';

    /** The request's status while the operator is asked; not an open status, so the guardian leaves it alone. */
    public const string AWAITING = 'awaiting_operator';

    /** Readings of one photo: the first and three more on request. */
    public const int MAX_READS = 4;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly AiSettings $settings,
    ) {}

    /**
     * Whether the operator is asked about this reading. A photo with no legible text gave a
     * description, not a reading: there is nothing to compare with a label.
     *
     * @param  array<string, mixed>  $data  the reader's answer
     */
    public function wanted(array $data): bool
    {
        if (! ($data['has_text'] ?? false)) {
            return false;
        }

        return match ($this->settings->photoReadingCheck()) {
            AiSettings::PHOTO_CHECK_NEVER => false,
            AiSettings::PHOTO_CHECK_UNSURE => $this->doubts($data) !== [],
            default => true,
        };
    }

    /**
     * What the reader itself was not sure of, for the operator to look at first.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function doubts(array $data): array
    {
        $doubts = [];
        foreach ([...(array) ($data['identifiers'] ?? []), ...(array) ($data['observations'] ?? [])] as $item) {
            $unsure = trim((string) ($item['uncertain_characters'] ?? ''));
            if ($unsure !== '' || (float) ($item['confidence'] ?? 1) < 0.8) {
                $doubts[] = trim((string) ($item['value'] ?? '')).($unsure !== '' ? ' — не уверен в «'.$unsure.'»' : ' — читается плохо');
            }
        }

        return array_values(array_unique($doubts));
    }

    /**
     * Puts the reading to the operator. The request waits for the answer; false when it was
     * closed or cancelled meanwhile.
     *
     * @param  array<string, mixed>  $data  the reader's answer
     * @param  array<string, mixed>  $payload  the request's payload with the photo's evidence
     */
    public function ask(TelegramUpdate $update, array $data, string $text, array $payload, ?string $note = null): bool
    {
        $payload[self::PAYLOAD_KEY] = ['reads' => (int) data_get($payload, self::PAYLOAD_KEY.'.reads', 0) + 1, 'text' => $text];
        $saved = TelegramUpdate::query()->whereKey($update->id)->whereNull('processed_at')->where('status', '!=', 'cancelled')
            ->update(['text' => $text, 'status' => self::AWAITING, 'error' => null,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        if (! $saved) {
            return false;
        }
        $doubts = $this->doubts($data);
        $this->telegram->sendMessage($update->chat_id, implode("\n", array_filter([
            $note,
            '📷 Прочитал с фото:',
            $text,
            $doubts === [] ? null : "\n⚠️ Сам не уверен: ".implode('; ', $doubts).'.',
            "\nВерно? Сверьте код с этикеткой: по нему пойдёт поиск.",
        ], fn (?string $line): bool => $line !== null)), $this->buttons($update->id));

        return true;
    }

    /**
     * The operator's press under the question.
     *
     * @return array{text: string, closes: bool, reading: string} what to tell them, whether the question is over, what was read
     */
    public function answer(int $photoUpdateId, string $action, string $chatId, string $userId): array
    {
        $photo = TelegramUpdate::query()->whereKey($photoUpdateId)->where('chat_id', $chatId)->first();
        $reading = (string) data_get($photo?->payload, self::PAYLOAD_KEY.'.text', '');
        if ($photo === null || $photo->status !== self::AWAITING) {
            return ['text' => 'Этот вопрос уже закрыт.', 'closes' => false, 'reading' => $reading];
        }
        if ($action === 'ok') {
            $this->forget($chatId, $userId);
            $payload = [...(array) $photo->payload, self::PAYLOAD_KEY => [...(array) data_get($photo->payload, self::PAYLOAD_KEY, []), 'confirmed' => true]];
            $started = $this->leave($photo, ['status' => 'transcribed', 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            if ($started) {
                ProcessTelegramMessage::dispatch($photo->id);
            }

            return ['text' => $started ? '✅ Принято, ищу.' : 'Этот вопрос уже закрыт.', 'closes' => $started, 'reading' => $reading];
        }
        if ($action === 'no') {
            $this->forget($chatId, $userId);
            $cancelled = $this->leave($photo, ['status' => 'cancelled', 'processed_at' => now()]);

            return ['text' => $cancelled ? '✖️ Отменено, поиск не запускал.' : 'Этот вопрос уже закрыт.', 'closes' => $cancelled, 'reading' => $reading];
        }
        if ($action === 'again') {
            if ((int) data_get($photo->payload, self::PAYLOAD_KEY.'.reads', 1) >= self::MAX_READS) {
                return ['text' => 'Прочитал уже '.self::MAX_READS.' раза. Исправьте текст сами или отмените.', 'closes' => false, 'reading' => $reading];
            }
            $this->forget($chatId, $userId);
            $again = $this->leave($photo, ['status' => 'received']);
            if ($again) {
                TranscribeTelegramPhoto::dispatch($photo->id);
            }

            return ['text' => $again ? '🔁 Читаю ещё раз.' : 'Этот вопрос уже закрыт.', 'closes' => $again, 'reading' => $reading];
        }
        // «Исправить»: the next message of this operator in this chat is the correction.
        Cache::put(self::fixKey($chatId, $userId), $photo->id, now()->addMinutes(30));

        return ['text' => '✏️ Пришлите исправление одним сообщением: весь текст или только то, что неверно — например «код M471A4G43MB1-CTD».',
            'closes' => false, 'reading' => $reading];
    }

    /**
     * The operator's correction, when one is awaited: the photo is read again with it and the
     * search starts without another question. Null when no correction is awaited: the text is
     * an ordinary message.
     */
    public function correct(string $chatId, string $userId, string $text): ?string
    {
        $photoUpdateId = Cache::pull(self::fixKey($chatId, $userId));
        $photo = $photoUpdateId ? TelegramUpdate::query()->whereKey($photoUpdateId)->where('chat_id', $chatId)->first() : null;
        if ($photo === null || $photo->status !== self::AWAITING) {
            return null;
        }
        $payload = (array) $photo->payload;
        $payload[self::PAYLOAD_KEY] = [...(array) ($payload[self::PAYLOAD_KEY] ?? []), 'correction' => mb_substr(trim($text), 0, 1000)];
        if (! $this->leave($photo, ['status' => 'received', 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)])) {
            return null;
        }
        TranscribeTelegramPhoto::dispatch($photo->id);

        return '✏️ Принял исправление: сверяю его с этикеткой и запускаю поиск.';
    }

    /** No correction is awaited any more: the operator moved on to something else. */
    public function forget(string $chatId, string $userId): void
    {
        Cache::forget(self::fixKey($chatId, $userId));
    }

    /** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
    public function buttons(int $photoUpdateId): array
    {
        return ['inline_keyboard' => [
            [['text' => '✅ Верно, искать', 'callback_data' => 'pr:ok:'.$photoUpdateId], ['text' => '✏️ Исправить', 'callback_data' => 'pr:fix:'.$photoUpdateId]],
            [['text' => '🔁 Прочитать ещё раз', 'callback_data' => 'pr:again:'.$photoUpdateId], ['text' => '✖️ Отмена', 'callback_data' => 'pr:no:'.$photoUpdateId]],
        ]];
    }

    /** Moves the request out of waiting, once: a second press finds it gone. */
    private function leave(TelegramUpdate $photo, array $fields): bool
    {
        return (bool) TelegramUpdate::query()->whereKey($photo->id)->where('status', self::AWAITING)->whereNull('processed_at')->update($fields);
    }

    private static function fixKey(string $chatId, string $userId): string
    {
        return "photo-reading-fix:{$chatId}:{$userId}";
    }
}
