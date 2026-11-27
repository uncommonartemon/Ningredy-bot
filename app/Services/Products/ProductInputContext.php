<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;

/** Input observations retain their origin; a transcription is never a human requirement. */
final class ProductInputContext
{
    public const string PAYLOAD_KEY = '_product_input';

    /** The product request a search was started for, kept on the update that started it. */
    public const string REQUEST_KEY = '_search_request';

    private const array SEARCH_PRIORITY = ['ean' => 10, 'upc' => 10, 'gtin' => 10, 'mpn' => 20, 'model' => 30, 'sku' => 40, 'family' => 60];

    /** Store the observed values, not an invented web citation or binding request. */
    public static function fromPhotoExtraction(TelegramUpdate $update, array $data, array $photo = []): array
    {
        $reference = 'telegram-photo:'.$update->id.':1';
        $evidence = [];
        $keys = [];
        foreach (($data['has_text'] ?? false) === true ? ['identifiers', 'observations'] : [] as $field) {
            foreach (is_array($data[$field] ?? null) ? $data[$field] : [] as $item) {
                if (! is_array($item) || self::text($item['value'] ?? null) === null) {
                    continue;
                }
                $kind = self::text($item['kind'] ?? null);
                $item['source_kind'] = in_array($item['source_kind'] ?? null, ['photo_label', 'handwritten'], true)
                    ? $item['source_kind'] : 'photo_transcription';
                $observation = self::observation($item, $reference, 1);
                if ($field === 'identifiers') {
                    $observation['key'] = $kind ?? 'unknown_identifier';
                    $observation['name'] = $kind ?? 'Identifier';
                    $keys[] = ['kind' => $kind ?? 'other', ...$observation];
                }
                $evidence[] = $observation;
            }
        }
        // Older providers/test doubles return only text+has_text. Preserve that
        // uncertainty rather than pretending the prose was a verified label field.
        if ($evidence === [] && self::text($data['text'] ?? null) !== null) {
            $evidence[] = self::observation([
                'key' => 'input_transcription', 'name' => 'Photo transcription', 'value' => $data['text'],
                'raw_value' => $data['text'], 'source_excerpt' => $data['text'],
                'source_kind' => ($data['has_text'] ?? false) ? 'photo_transcription' : 'visual_observation',
            ], $reference, 1);
        }

        return [
            'version' => 1,
            'original_operator_request' => self::text(data_get($update->payload, 'message.caption')),
            'search_text' => self::text($data['text'] ?? null),
            'input_evidence' => $evidence,
            'search_keys' => $keys,
            'photos' => [['source_ref' => $reference, 'photo_index' => 1, ...array_intersect_key($photo, array_flip(['sha256', 'file_unique_id']))]],
        ];
    }

    public static function forDraft(ProductDraft $draft): array
    {
        $update = $draft->telegramUpdate;

        return $update ? self::forUpdate($update) : self::empty();
    }

    /**
     * The product request a search was started for, when the assistant says it came from
     * earlier messages of this chat. A follow-up that only instructs (search again, how to treat
     * the condition) names no product; read as the request it made the card assembly say the
     * request names no product (live report 2026-11-27, #635) and put an instruction about the
     * condition in the place of the product (#706). The earlier messages
     * are found by their own text, copied by the assistant; only real messages of this chat
     * count, and their text is kept as the operator wrote it. The current message that is not
     * part of the request travels as the operator's follow-up.
     *
     * @param  list<string>  $quotes  the operator's messages as the assistant copied them
     * @return array{text: string, message_ids: list<int>, follow_up: ?string, unmatched: list<string>}|null
     */
    public static function rememberSearchRequest(TelegramUpdate $update, array $quotes): ?array
    {
        $quotes = array_values(array_filter(array_map(fn (mixed $quote): ?string => self::text($quote), $quotes)));
        if ($quotes === []) {
            return null;
        }
        $candidates = TelegramUpdate::query()
            ->where('chat_id', $update->chat_id)->where('telegram_user_id', $update->telegram_user_id)
            ->where('id', '<=', $update->id)->where('created_at', '>=', now()->subDays(2))
            ->whereNotNull('text')->orderByDesc('id')->limit(40)->get()
            ->reject(fn (TelegramUpdate $candidate): bool => isset($candidate->payload['callback_query'])
                || str_starts_with((string) $candidate->text, 'draft:'));
        $matched = [];
        $unmatched = [];
        foreach ($quotes as $quote) {
            $hit = $candidates->first(fn (TelegramUpdate $candidate): bool => self::sameMessage((string) $candidate->text, $quote));
            $hit === null ? $unmatched[] = $quote : $matched[$hit->id] = $hit;
        }
        if ($matched === []) {
            return null;
        }
        ksort($matched);
        // A photo's text is the bot's reading of its label, never the operator's words: only its
        // caption is; what the label says comes with the photo's own evidence (forUpdate()).
        $words = array_filter(array_map(fn (TelegramUpdate $message): ?string => self::photoMessage($message)
            ? self::text(data_get($message->payload, 'message.caption'))
            : self::text($message->text), $matched));
        $record = [
            'text' => implode("\n\n", $words),
            'message_ids' => array_keys($matched),
            'follow_up' => isset($matched[$update->id]) ? null
                : (self::photoMessage($update) ? self::text(data_get($update->payload, 'message.caption')) : self::text($update->text)),
            'unmatched' => $unmatched,
        ];
        $fresh = $update->fresh() ?? $update;
        $payload = is_array($fresh->payload) ? $fresh->payload : [];
        $payload[self::REQUEST_KEY] = $record;
        $fresh->update(['payload' => $payload]);
        $update->setAttribute('payload', $payload);

        return $record;
    }

    private static function photoMessage(TelegramUpdate $message): bool
    {
        $payload = is_array($message->payload) ? $message->payload : [];

        return ! empty(data_get($payload, 'message.photo')) || is_array($payload[self::PAYLOAD_KEY] ?? null);
    }

    /** One message, whatever spaces and case the copy has; a copy may also be a part of it. */
    private static function sameMessage(string $message, string $quote): bool
    {
        $normal = fn (string $text): string => mb_strtolower(trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', trim($text, " \t\n\r\0\x0B\"'«»“”„"))));
        $message = $normal($message);
        $quote = $normal($quote);

        return $message !== '' && $quote !== ''
            && ($message === $quote || (mb_strlen($quote) >= 4 && str_contains($message, $quote)));
    }

    public static function forUpdate(TelegramUpdate $update): array
    {
        $payload = is_array($update->payload) ? $update->payload : [];
        $request = $payload[self::REQUEST_KEY] ?? null;
        // A bare label photo as the request has no words of the operator: its evidence still counts.
        if (is_array($request) && ((array) ($request['message_ids'] ?? [])) !== []) {
            $own = $payload;
            unset($own[self::REQUEST_KEY]);
            $alone = clone $update;
            $alone->setAttribute('payload', $own);
            $current = self::forUpdate($alone);
            $result = self::empty();
            $result['original_operator_request'] = self::text($request['text'] ?? null);
            if (self::text($request['follow_up'] ?? null) !== null) {
                $result['operator_follow_up'] = self::text($request['follow_up']);
            }
            // What a photo of the request read (its label) belongs to the request, not to the message it came in.
            $earlier = TelegramUpdate::query()->whereIn('id', array_map('intval', (array) ($request['message_ids'] ?? [])))
                ->where('id', '!=', $update->id)->orderBy('id')->get()->map(fn (TelegramUpdate $message): array => self::forUpdate($message));
            foreach ([...$earlier->all(), $current] as $part) {
                $result['input_evidence'] = [...$result['input_evidence'], ...$part['input_evidence']];
                $result['search_keys'] = [...$result['search_keys'], ...$part['search_keys']];
            }
            $result['search_keys'] = self::rankKeys($result['search_keys']);
            $result['search_plan'] = self::searchPlan($result['search_keys']);

            return $result;
        }
        $saved = $payload[self::PAYLOAD_KEY] ?? null;
        if (is_array($saved) && ($saved['version'] ?? null) === 1) {
            $result = self::empty();
            $result['original_operator_request'] = self::text($saved['original_operator_request'] ?? null);
            $result['input_evidence'] = array_values(array_filter(is_array($saved['input_evidence'] ?? null) ? $saved['input_evidence'] : [], 'is_array'));
            $result['search_keys'] = self::rankKeys(is_array($saved['search_keys'] ?? null) ? $saved['search_keys'] : []);
            $result['search_plan'] = self::searchPlan($result['search_keys']);

            return $result;
        }

        $manual = $payload['_manual_product_intake'] ?? null;
        if (is_array($manual)) {
            $result = self::empty();
            $result['original_operator_request'] = self::text($manual['original_description'] ?? null);
            foreach ($manual['fact_provenance'] ?? [] as $fact) {
                if (! is_array($fact) || self::text($fact['value'] ?? null) === null) {
                    continue;
                }
                $photoIndex = ($fact['source'] ?? null) === 'photo_label' ? (int) ($fact['photo_index'] ?? 0) : null;
                $sourceKind = $photoIndex > 0 ? 'photo_label' : (($fact['source'] ?? null) === 'description' ? 'user_text' : 'photo_transcription');
                $reference = $photoIndex > 0 ? 'telegram-photo:'.$update->id.':'.$photoIndex : 'telegram-message:'.$update->id;
                $item = self::observation([
                    ...$fact, 'raw_value' => $fact['value'], 'source_excerpt' => $fact['quote'] ?? '',
                    'source_kind' => $sourceKind,
                ], $reference, $photoIndex > 0 ? $photoIndex : null);
                $result['input_evidence'][] = $item;
                if (isset(self::SEARCH_PRIORITY[$item['key']])) {
                    $result['search_keys'][] = ['kind' => $item['key'], ...$item];
                }
            }
            $result['search_keys'] = self::rankKeys($result['search_keys']);
            $result['search_plan'] = self::searchPlan($result['search_keys']);

            return $result;
        }

        // Legacy photo updates used text for OCR. Its exact printed/handwritten
        // origin cannot be reconstructed reliably, so never promote it to human text.
        $photoInput = ! empty(data_get($payload, 'message.photo'));
        $humanText = self::text(data_get($payload, 'message.caption'))
            ?? (! $photoInput ? self::text($update->text) : null);
        $result = self::empty();
        $result['original_operator_request'] = $humanText;
        if ($photoInput && self::text($update->text) !== null) {
            $result['input_evidence'][] = self::observation([
                'key' => 'input_transcription', 'name' => 'Legacy photo transcription', 'value' => $update->text,
                'raw_value' => $update->text, 'source_excerpt' => $update->text, 'source_kind' => 'photo_transcription',
            ], 'telegram-photo:'.$update->id.':1', 1);
        }

        return $result;
    }

    private static function empty(): array
    {
        return ['original_operator_request' => null, 'input_evidence' => [], 'search_keys' => [], 'search_plan' => []];
    }

    private static function observation(array $item, string $reference, ?int $photoIndex): array
    {
        $source = $item['source_kind'] ?? 'photo_transcription';
        $source = in_array($source, ['photo_label', 'handwritten', 'user_text', 'photo_transcription', 'visual_observation'], true) ? $source : 'photo_transcription';
        $confidence = is_numeric($item['confidence'] ?? null) ? max(0, min(1, (float) $item['confidence'])) : null;

        return [
            'key' => (string) ($item['key'] ?? 'observation'),
            'name' => (string) ($item['name'] ?? $item['key'] ?? 'Observation'),
            'value' => (string) ($item['value'] ?? ''),
            'raw_value' => (string) ($item['raw_value'] ?? $item['value'] ?? ''),
            'source_excerpt' => (string) ($item['source_excerpt'] ?? $item['raw_value'] ?? $item['value'] ?? ''),
            'source_kind' => $source,
            'source_ref' => $reference,
            'photo_ref' => $photoIndex !== null ? $reference : null,
            'photo_index' => $photoIndex,
            'confidence' => $confidence,
            'uncertain_characters' => self::text($item['uncertain_characters'] ?? null),
        ];
    }

    private static function rankKeys(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (! is_array($key) || self::text($key['value'] ?? null) === null) {
                continue;
            }
            $kind = $key['kind'] ?? 'other';
            $priority = self::SEARCH_PRIORITY[$kind] ?? 100;
            $clear = ($key['uncertain_characters'] ?? null) === null && ($key['confidence'] ?? 0) >= 0.8;
            $barcode = in_array($kind, ['ean', 'upc', 'gtin'], true);
            $validBarcode = ! $barcode || self::validGtin((string) $key['value']);
            $result[] = [...$key, 'priority' => $priority, 'usable_for_search' => $priority < 100 && $clear && $validBarcode];
        }
        usort($result, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $result;
    }

    private static function searchPlan(array $keys): array
    {
        $brand = collect($keys)->first(fn (array $key): bool => ($key['kind'] ?? null) === 'brand'
            && ($key['confidence'] ?? 0) >= 0.8 && ($key['uncertain_characters'] ?? null) === null)['value'] ?? null;
        $plan = [];
        $seen = [];
        foreach ($keys as $key) {
            if (! $key['usable_for_search']) {
                continue;
            }
            $value = trim($key['value']);
            $query = $value;
            if ($brand && in_array($key['kind'], ['model', 'family'], true) && stripos($value, $brand) === false) {
                $query = $brand.' '.$value;
            }
            if (isset($seen[mb_strtolower($query)])) {
                continue;
            }
            $seen[mb_strtolower($query)] = true;
            $plan[] = ['purpose' => $key['kind'] === 'family' ? 'photo_candidates' : 'identity_and_facts',
                'query' => $query, 'priority' => $key['priority'], 'identifier_kinds' => [$key['kind']],
                'source_refs' => [$key['source_ref'] ?? null]];
        }

        return $plan;
    }

    private static function validGtin(string $value): bool
    {
        $digits = preg_replace('/[\s-]+/', '', $value);
        if (! ctype_digit($digits) || ! in_array(strlen($digits), [8, 12, 13, 14], true)) {
            return false;
        }
        $sum = 0;
        for ($position = strlen($digits) - 2, $weight = 3; $position >= 0; $position--, $weight = 4 - $weight) {
            $sum += (int) $digits[$position] * $weight;
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
