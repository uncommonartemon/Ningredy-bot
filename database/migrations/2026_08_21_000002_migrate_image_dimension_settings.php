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

        $legacyMinimum = DB::table('app_settings')
            ->where('key', 'ai.image_minimum_side')
            ->value('value');
        $minimum = is_numeric($legacyMinimum) ? (int) $legacyMinimum : 700;
        $timestamp = now();

        foreach (['ai.image_minimum_width', 'ai.image_minimum_height'] as $key) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => (string) $minimum, 'updated_at' => $timestamp, 'created_at' => $timestamp],
            );
        }

        DB::table('app_settings')
            ->whereIn('key', ['ai.image_minimum_side', 'ai.confirmed_gallery_minimum_side'])
            ->delete();
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        $width = DB::table('app_settings')->where('key', 'ai.image_minimum_width')->value('value');
        $height = DB::table('app_settings')->where('key', 'ai.image_minimum_height')->value('value');
        $minimum = max(
            is_numeric($width) ? (int) $width : 700,
            is_numeric($height) ? (int) $height : 700,
        );
        $timestamp = now();

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'ai.image_minimum_side'],
            ['value' => (string) $minimum, 'updated_at' => $timestamp, 'created_at' => $timestamp],
        );
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'ai.confirmed_gallery_minimum_side'],
            ['value' => (string) $minimum, 'updated_at' => $timestamp, 'created_at' => $timestamp],
        );

        DB::table('app_settings')
            ->whereIn('key', ['ai.image_minimum_width', 'ai.image_minimum_height'])
            ->delete();
    }
};
