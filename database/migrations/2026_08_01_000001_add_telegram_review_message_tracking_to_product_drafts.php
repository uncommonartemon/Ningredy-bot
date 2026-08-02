<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->string('telegram_review_chat_id')->nullable();
            $table->json('telegram_review_message_ids')->nullable();
            $table->boolean('telegram_review_has_media')->default(false);
            $table->json('telegram_control_message_ids')->nullable();
            $table->timestamp('telegram_review_finalized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_review_chat_id',
                'telegram_review_message_ids',
                'telegram_review_has_media',
                'telegram_control_message_ids',
                'telegram_review_finalized_at',
            ]);
        });
    }
};
