<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Operator's decision, 2026-11-07, no rule about
 * boxes. Shops show a boxed processor by its box, and the rule left the card with no photo (live
 * #386, #422). Only the rule's sentence leaves a component's hint; the rest of it stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        $categories = DB::table('categories')->where('product_type_affinity', 'component')
            ->where('product_search_hint', 'like', '%Без фото коробки%')->get(['id', 'product_search_hint']);
        foreach ($categories as $category) {
            $hint = trim((string) preg_replace('/Без фото коробки(?:\s*\(OEM\/tray\))?\.?\s*/u', '', (string) $category->product_search_hint));
            DB::table('categories')->where('id', $category->id)->update(['product_search_hint' => $hint === '' ? null : $hint, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // The rule was dropped by the operator's word; nothing brings it back.
    }
};
