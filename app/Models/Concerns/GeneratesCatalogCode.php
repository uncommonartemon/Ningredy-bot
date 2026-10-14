<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait GeneratesCatalogCode
{
    protected static function bootGeneratesCatalogCode(): void
    {
        static::creating(function (Model $record): void {
            $column = $record->catalogCodeColumn();
            if (filled($record->{$column})) {
                return;
            }

            $base = Str::substr(Str::slug($record->{$record->catalogCodeSource()}) ?: 'item', 0, 255);
            $candidate = $base;
            for ($suffix = 2; static::query()->where($column, $candidate)->exists(); $suffix++) {
                $candidate = Str::substr($base, 0, 255 - strlen((string) $suffix) - 1).'-'.$suffix;
            }
            $record->{$column} = $candidate;
        });
    }

    protected function catalogCodeColumn(): string
    {
        return 'slug';
    }

    protected function catalogCodeSource(): string
    {
        return 'name';
    }
}
