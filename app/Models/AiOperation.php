<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiOperation extends Model
{
    protected $fillable = [
        'telegram_update_id', 'telegram_user_id', 'tool', 'action', 'target_type',
        'target_id', 'idempotency_key', 'payload', 'result', 'status', 'error', 'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function telegramUpdate(): BelongsTo
    {
        return $this->belongsTo(TelegramUpdate::class);
    }
}
