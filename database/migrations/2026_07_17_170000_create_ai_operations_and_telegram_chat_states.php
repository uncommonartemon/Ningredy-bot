<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_update_id')->nullable()->constrained()->nullOnDelete();
            $table->string('telegram_user_id')->nullable()->index();
            $table->string('tool');
            $table->string('action')->index();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->string('status')->default('completed')->index();
            $table->text('error')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
        });

        Schema::create('telegram_chat_states', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->unique();
            $table->string('telegram_user_id')->nullable()->index();
            $table->string('conversation_id', 36)->nullable()->index();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_chat_states');
        Schema::dropIfExists('ai_operations');
    }
};
