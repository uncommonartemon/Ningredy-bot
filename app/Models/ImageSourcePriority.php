<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ImageSourcePriority extends Model
{
    protected $fillable = [
        'name', 'domain', 'aliases', 'source_type', 'priority', 'is_enabled', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'priority' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ImageSourcePriority $source): void {
            $source->domain = self::normalizeDomain($source->domain);
            $source->aliases = collect($source->aliases ?? [])
                ->map(fn (mixed $domain): string => self::normalizeDomain((string) $domain))
                ->filter()
                ->unique()
                ->values()
                ->all();
        });
    }

    public static function normalizeDomain(string $value): string
    {
        $value = Str::lower(trim($value));
        $host = parse_url(Str::startsWith($value, ['http://', 'https://']) ? $value : 'https://'.$value, PHP_URL_HOST);

        return Str::after((string) $host, 'www.');
    }
}
