<?php

namespace App\Services\Admin;

use Illuminate\Support\Arr;

/** Redaction happens before a value can enter a Livewire response or snapshot. */
class DatabaseValueRedactor
{
    public const HIDDEN = '[скрыто: секретные данные]';

    private ?array $configuredSecrets = null;

    public function hiddenColumn(string $table, string $column): bool
    {
        return $this->secretKey($column)
            || in_array($column, match ($table) {
                'sessions' => ['id', 'payload'],
                'cache' => ['value'],
                'cache_locks' => ['owner'],
                'jobs', 'failed_jobs' => ['payload'],
                'job_batches' => ['options'],
                'app_settings' => ['value'],
                default => [],
            }, true);
    }

    public function secretKey(string $key): bool
    {
        // Header-style names (x-api-key, X-Api-Key) and spaced labels use the
        // same words as snake_case keys; normalize before matching.
        $key = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
        $key = str_replace(['-', ' '], '_', $key);

        return (bool) preg_match('/(?:^|[_.])(?:password|passwd|secret|authorization|cookie|cookies|credentials|private_key|api_key|apikey|access_key|access_token|refresh_token|remember_token|bot_token|webhook_secret|session_id|session_payload|csrf_token|token)(?:$|[_.])/i', $key);
    }

    public function value(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->secretKey($key)) {
            return self::HIDDEN;
        }
        if (is_array($value)) {
            // Key/value settings can be nested inside a response or operation.
            $secretSetting = is_string($value['key'] ?? null) && $this->secretKey($value['key']);
            foreach ($value as $name => $item) {
                $value[$name] = $secretSetting && $name === 'value'
                    ? self::HIDDEN : $this->value($item, (string) $name);
            }

            return $value;
        }
        if (! is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->value($decoded);
        }

        if ($this->configuredSecrets === null) {
            $this->configuredSecrets = [];
            foreach (Arr::dot(config()->all()) as $name => $configured) {
                if (is_string($configured) && strlen($configured) >= 8
                    && ($this->secretKey($name) || $name === 'app.key' || preg_match('/^ai\.providers\.[^.]+\.key$/D', $name))) {
                    $this->configuredSecrets[] = $configured;
                }
            }
        }
        $value = str_replace($this->configuredSecrets, self::HIDDEN, $value);
        $value = preg_replace('/\b(?:sk-[A-Za-z0-9_-]{12,}|\d{6,}:[A-Za-z0-9_-]{20,})\b/u', self::HIDDEN, $value);
        $value = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/iu', '$1'.self::HIDDEN, $value);
        $value = preg_replace('/([?&](?:token|api_key|key|secret|signature|access_token)=)[^&\s"<>]+/iu', '$1'.self::HIDDEN, $value);

        return preg_replace('/((?:password|passwd|api[_-]?key|secret|authorization|cookie|access[_-]?token|refresh[_-]?token)\s*[=:]\s*)[^\r\n,;]+/iu', '$1'.self::HIDDEN, $value);
    }

    public function text(mixed $value): string
    {
        $value = $this->value($value);

        return is_array($value)
            ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            : ($value === null ? '—' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
    }
}
