<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'ai.image_minimum_height'],
            ['value' => '0', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'ai.image_minimum_height'],
            ['value' => '700', 'updated_at' => now(), 'created_at' => now()],
        );
    }
};
