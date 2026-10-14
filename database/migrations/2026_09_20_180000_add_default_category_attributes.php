<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attribute_definition_category', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false);
        });

        // Initial editable defaults requested for laptops, not a runtime rule.
        $categories = DB::table('categories')->where('product_type_affinity', 'laptop')->pluck('id');
        $definitions = DB::table('attribute_definitions')->whereIn('key', ['gpu', 'cpu', 'display'])->pluck('id', 'key');
        foreach ($categories as $categoryId) {
            foreach (['gpu', 'cpu', 'display'] as $sort => $key) {
                if (isset($definitions[$key])) {
                    DB::table('attribute_definition_category')->updateOrInsert([
                        'category_id' => $categoryId, 'attribute_definition_id' => $definitions[$key],
                    ], ['is_default' => true, 'sort_order' => $sort]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('attribute_definition_category', fn (Blueprint $table) => $table->dropColumn('is_default'));
    }
};
