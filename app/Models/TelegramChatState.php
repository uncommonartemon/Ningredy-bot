<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramChatState extends Model
{
    protected $fillable = ['chat_id', 'telegram_user_id', 'conversation_id', 'boot_id', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
