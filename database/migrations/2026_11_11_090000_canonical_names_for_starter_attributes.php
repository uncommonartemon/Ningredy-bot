<?php

use Database\Seeders\StarterCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The seven starter characteristics had a Russian label and no names for the shop: each got the
 * name of the first card that used it (live: «ram» became «Capacity» from a memory module, and a
 * laptop's memory read «Kapacita»). They get their own names, the ones the storefront's built-in
 * dictionary already showed where the database had none.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('attribute_definitions', 'translations')) {
            return;
        }
        foreach (StarterCatalogSeeder::ATTRIBUTE_NAMES as $key => $names) {
            $row = DB::table('attribute_definitions')->where('key', $key)->first(['id', 'translations']);
            if ($row === null) {
                continue;
            }
            $current = json_decode((string) $row->translations, true) ?: [];
            DB::table('attribute_definitions')->where('id', $row->id)->update([
                'translations' => json_encode([...$current, ...$names], JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The earlier names were accidents of the first card; nothing brings them back.
    }
};
