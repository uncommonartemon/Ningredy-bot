<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Operator, 2026-11-11: «если позиция есть, значит по дефолту 1 есть». A card is made because
 * the item is there, so a configuration on sale whose stock nobody ever stated (nothing in
 * stock, availability «уточнить», no unit ever written for it) is one item in stock. What was
 * stated - «нет», a sold unit, a product taken off sale - stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $variants = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.status', 'published')->where('products.is_active', true)
            ->where('product_variants.is_active', true)
            ->where('product_variants.quantity', 0)->where('product_variants.stock_status', 'unknown')
            ->whereNotExists(fn ($units) => $units->select(DB::raw(1))->from('stock_units')
                ->whereColumn('stock_units.product_variant_id', 'product_variants.id'))
            ->orderBy('product_variants.id')
            ->get(['product_variants.id', 'product_variants.currency', 'products.title']);

        foreach ($variants as $variant) {
            DB::table('product_variants')->where('id', $variant->id)
                ->update(['quantity' => 1, 'stock_status' => 'in_stock', 'updated_at' => $now]);
            $id = DB::table('stock_units')->insertGetId(['product_variant_id' => $variant->id, 'status' => 'in_stock',
                'product_title' => $variant->title, 'currency' => $variant->currency, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('stock_units')->where('id', $id)->update(['code' => 'U-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        // Which items were counted here and which the operator added since cannot be told apart.
    }
};
