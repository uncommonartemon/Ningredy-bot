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
}
