<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Removed: source priority is now driven by real ProductGalleryRecipe
// success/failure history (see ProductSourcePriority) instead of this
// manually maintained domain list, which was never actually curated by
// hand and mostly duplicated the hardcoded trusted-retailer fallback.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('image_source_priorities');
    }

    public function down(): void
    {
        Schema::create('image_source_priorities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('domain')->unique();
            $table->json('aliases')->nullable();
            $table->string('source_type', 32);
            $table->unsignedInteger('priority')->default(500);
            $table->boolean('is_enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'priority']);
        });
    }
};
