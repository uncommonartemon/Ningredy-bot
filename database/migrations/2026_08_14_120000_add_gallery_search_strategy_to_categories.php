<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('gallery_search_strategy', 32)
                ->default(Category::GALLERY_SEARCH_AUTO)
                ->after('icon');
        });

        DB::table('categories')
            ->where('slug', 'components')
            ->update(['gallery_search_strategy' => Category::GALLERY_SEARCH_VISION_FIRST]);

        DB::table('categories')
            ->whereIn('slug', ['laptops', 'computers'])
            ->update(['gallery_search_strategy' => Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('gallery_search_strategy');
        });
    }
};
