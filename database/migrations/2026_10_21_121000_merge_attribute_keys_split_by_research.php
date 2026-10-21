<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Research named the same thing under two keys, so the storefront showed two
 * "screen size" filters and two "Rozhraní" ones. Values move to the key most
 * products already use; a product that has both keeps both, nothing is deleted.
 * The emptied definition stops being a filter and stays editable in the admin.
 */
return new class extends Migration
{
    private const MERGES = ['display_size' => 'screen_size', 'interfaces' => 'interface'];

    public function up(): void
    {
        foreach (self::MERGES as $from => $to) {
            $source = DB::table('attribute_definitions')->where('key', $from)->value('id');
            $target = DB::table('attribute_definitions')->where('key', $to)->value('id');
            if ($source === null || $target === null) {
                continue;
            }
            DB::table('attribute_values as moving')->where('moving.attribute_definition_id', $source)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('attribute_values as present')
                    ->where('present.attribute_definition_id', $target)
                    ->whereRaw('coalesce(present.product_id, 0) = coalesce(moving.product_id, 0)')
                    ->whereRaw('coalesce(present.product_variant_id, 0) = coalesce(moving.product_variant_id, 0)'))
                ->update(['attribute_definition_id' => $target]);
            foreach (DB::table('attribute_definition_category')->where('attribute_definition_id', $source)->pluck('category_id') as $category) {
                DB::table('attribute_definition_category')->insertOrIgnore(['attribute_definition_id' => $target, 'category_id' => $category]);
            }
            if (! DB::table('attribute_values')->where('attribute_definition_id', $source)->exists()) {
                DB::table('attribute_definitions')->where('id', $source)->update(['is_filterable' => false]);
            }
        }
    }

    public function down(): void
    {
        // Merged values cannot be told apart from the ones already under the target key.
    }
};
