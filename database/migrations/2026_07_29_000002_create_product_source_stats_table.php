<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_source_stats', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->unique();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('extraction_success_count')->default(0);
            $table->unsignedInteger('accepted_gallery_count')->default(0);
            $table->unsignedInteger('accepted_image_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->string('last_failure_kind')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_source_stats');
    }
};
