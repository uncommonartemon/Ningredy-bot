<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramUpdate extends Model
{
    protected $fillable = [
        'update_id', 'telegram_user_id', 'chat_id', 'message_id', 'username',
        'text', 'reply_to_text', 'payload', 'status', 'error', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }

    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    public function productDrafts(): HasMany
    {
        return $this->hasMany(ProductDraft::class);
    }

    public function aiOperations(): HasMany
    {
        return $this->hasMany(AiOperation::class);
    }
}
