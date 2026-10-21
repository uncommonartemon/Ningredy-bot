<?php

namespace App\Services\Telegram;

use App\Models\ProductDraft;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Short-lived UI state, isolated by chat, operator and draft generation. */
class DraftTelegramInteractionState
{
    public function remember(string $chat, string $user, ProductDraft $draft, string $kind, array $data = []): array
    {
        $state = [...$data, 'draft_id' => $draft->id, 'generation' => $draft->telegram_update_id,
            'kind' => $kind, 'token' => Str::random(12)];
        $until = now()->addMinutes($kind === 'processing' ? 20 : 10);
        $state['expires_at'] = $until->getTimestamp();
        Cache::put($this->key($chat, $user), $state, $until);
        // The expiry notice guards the hour after the prompt lapses; a message
        // sent much later is an ordinary request, not the forgotten input.
        Cache::put($this->key($chat, $user).':last', $state, $until->copy()->addHour());

        return $state;
    }

    public function get(string $chat, string $user): ?array
    {
        $value = Cache::get($this->key($chat, $user));

        return is_array($value) ? $value : null;
    }

    public function consume(string $chat, string $user, string $token): ?array
    {
        return Cache::lock($this->key($chat, $user).':lock', 5)->get(function () use ($chat, $user, $token): ?array {
            $state = $this->get($chat, $user);
            if (! $state || ! hash_equals($state['token'], $token)) {
                return null;
            }
            Cache::forget($this->key($chat, $user));
            Cache::forget($this->key($chat, $user).':last');

            return $state;
        }) ?: null;
    }

    public function clear(string $chat, string $user): void
    {
        Cache::forget($this->key($chat, $user));
        Cache::forget($this->key($chat, $user).':last');
        // Remove legacy chat-wide prompts on /reset or /new too.
        Cache::forget("draft-source-hint-await:{$chat}");
    }

    /** An expired manual prompt must not silently become a paid product search. */
    public function expired(string $chat, string $user): ?array
    {
        if ($this->get($chat, $user) !== null) {
            return null;
        }
        $state = Cache::get($this->key($chat, $user).':last');

        return is_array($state) && (int) ($state['expires_at'] ?? PHP_INT_MAX) <= now()->getTimestamp()
            ? $state : null;
    }

    private function key(string $chat, string $user): string
    {
        return "draft-ui:{$chat}:{$user}";
    }
}
