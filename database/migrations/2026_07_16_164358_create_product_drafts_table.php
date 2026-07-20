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
        Schema::create('product_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_update_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_run_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending_review')->index();
            $table->string('requested_by_telegram_user_id');
            $table->string('title');
            $table->string('brand')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->string('color')->nullable();
            $table->text('description')->nullable();
            $table->json('specifications');
            $table->json('sources');
            $table->json('image_urls');
            $table->decimal('confidence', 5, 4)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_drafts');
    }
};
