<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->string('telegram_user_id')->nullable()->index();
            $table->string('chat_id')->nullable()->index();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('username')->nullable();
            $table->text('text')->nullable();
            $table->json('payload');
            $table->string('status')->default('received')->index();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
