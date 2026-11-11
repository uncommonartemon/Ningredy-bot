<?php

use Database\Seeders\StarterCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The kinds of components (SSD, HDD, memory, processors, graphics cards) under
 * «Комплектующие», each with the characteristics a card of that kind must carry
 * (operator's decision, 2026-11-04; used parts carry health rows).
 * Only what is missing is added; the rows are the operator's to edit afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(StarterCatalogSeeder::class)->seedComponentKinds();
    }

    public function down(): void
    {
        // A kind that already holds products stays: its products would lose their category.
        foreach (array_keys(StarterCatalogSeeder::COMPONENT_KINDS) as $slug) {
            $id = DB::table('categories')->where('slug', $slug)->value('id');
            if ($id !== null && ! DB::table('products')->where('category_id', $id)->exists()) {
                DB::table('categories')->where('id', $id)->delete();
            }
        }
    }
};
