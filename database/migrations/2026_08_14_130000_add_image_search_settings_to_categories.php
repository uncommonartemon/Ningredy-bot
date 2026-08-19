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
            $table->unsignedTinyInteger('minimum_verified_images')->default(3)->after('gallery_search_strategy');
            $table->text('product_search_hint')->nullable()->after('minimum_verified_images');
        });

        DB::table('categories')->where('slug', 'components')->update([
            'minimum_verified_images' => 1,
        ]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn(['minimum_verified_images', 'product_search_hint']);
        });
    }
};
