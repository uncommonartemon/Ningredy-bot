<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->unsignedSmallInteger('minimum_image_width')->nullable()->after('product_search_hint');
            $table->unsignedSmallInteger('minimum_image_height')->nullable()->after('minimum_image_width');
        });

        // Component photos (CPU/GPU/RAM boxes, etc.) are routinely 500-700px
        // on real retailer pages - the global 700px default was rejecting
        // plenty of genuinely usable photos for this category specifically.
        DB::table('categories')->where('slug', 'components')->update([
            'minimum_image_width' => 500,
        ]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn(['minimum_image_width', 'minimum_image_height']);
        });
    }
};
