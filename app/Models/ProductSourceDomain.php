<?php

namespace App\Models;

use App\Services\Products\ProductSourcePriority;
use Illuminate\Database\Eloquent\Model;

class ProductSourceDomain extends Model
{
    protected $fillable = [
        'domain',
        'agent_hint',
        'auto_agent_hint',
    ];

    public function setDomainAttribute(mixed $value): void
    {
        $raw = mb_strtolower(trim((string) $value));
        $normalized = ProductSourcePriority::host(
            str_contains($raw, '://') ? $raw : 'https://'.$raw,
        );

        $this->attributes['domain'] = $normalized !== '' ? $normalized : $raw;
    }

    /**
     * Lower-trust AI observation for future agents on this domain. The oldest
     * auto note is dropped first; the operator's agent_hint is never touched,
     * and the same observation is not stored twice.
     */
    public function appendAutoNote(string $category, string $note, int $keep = 5): int
    {
        $note = mb_substr(trim($note), 0, 300);
        $lines = array_values(array_filter(preg_split('/\R/', (string) $this->auto_agent_hint) ?: [],
            fn (string $line): bool => trim($line) !== ''));
        if ($note === '' || collect($lines)->contains(fn (string $line): bool => str_ends_with($line, '] '.$note))) {
            return count($lines);
        }
        $lines = array_slice([...$lines, '[auto '.$category.' '.now()->toDateString().'] '.$note], -$keep);
        $this->update(['auto_agent_hint' => implode("\n", $lines)]);

        return count($lines);
    }
}
