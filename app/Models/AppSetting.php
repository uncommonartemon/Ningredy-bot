<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    public const TELEGRAM_PROXY_URL = 'telegram.proxy_url';

    protected $fillable = ['key', 'value'];

    public static function valueFor(string $key, ?string $fallback = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $fallback;
    }

    public static function put(string $key, ?string $value): self
    {
        return static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }
}
