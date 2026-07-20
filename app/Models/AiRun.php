<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRun extends Model
{
    protected $fillable = [
        'telegram_update_id', 'invocation_id', 'provider', 'model', 'status',
        'prompt', 'response', 'usage', 'error', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array', 'usage' => 'array',
            'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function telegramUpdate(): BelongsTo
    {
        return $this->belongsTo(TelegramUpdate::class);
    }

    public function productDrafts(): HasMany
    {
        return $this->hasMany(ProductDraft::class);
    }
}
