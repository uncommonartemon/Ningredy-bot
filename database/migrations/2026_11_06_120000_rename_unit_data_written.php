<?php

use Database\Seeders\StarterCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «tbw_written» read like a maker's rating: research on a new Samsung PM9A1 wrote its rated
 * 300 TBW there (live #403), and the card said 300 TB had been written on the drive. The state
 * of one used unit gets a key nobody fills from a datasheet; the rating gets its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = StarterCatalogSeeder::COMPONENT_ATTRIBUTES['data_written_tb'];
        if (! DB::table('attribute_definitions')->where('key', 'data_written_tb')->exists()) {
            DB::table('attribute_definitions')->where('key', 'tbw_written')->update(['key' => 'data_written_tb', 'label' => $row['label'],
                'translations' => json_encode($row['translations'], JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
        app(StarterCatalogSeeder::class)->seedComponentKinds();
    }

    public function down(): void
    {
        DB::table('attribute_definitions')->where('key', 'data_written_tb')->update(['key' => 'tbw_written', 'label' => 'Записано (TBW)', 'updated_at' => now()]);
    }
};
