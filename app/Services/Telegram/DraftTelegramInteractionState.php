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
        Cache::put($this->key($chat, $user), $state, now()->addMinutes(10));

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

            return $state;
        }) ?: null;
    }

    public function clear(string $chat, string $user): void
    {
        Cache::forget($this->key($chat, $user));
        // Remove legacy chat-wide prompts on /reset or /new too.
        Cache::forget("draft-source-hint-await:{$chat}");
    }

    private function key(string $chat, string $user): string
    {
        return "draft-ui:{$chat}:{$user}";
    }
}
