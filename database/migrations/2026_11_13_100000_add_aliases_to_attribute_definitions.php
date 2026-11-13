<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Other names a characteristic answers to (decision of 2026-11-13): a card that calls the
 * system memory «memory_size» or «Memory capacity» lands in «ram» instead of creating a second
 * characteristic beside it. Stored lowercased, with spaces for underscores and dashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attribute_definitions', function (Blueprint $table) {
            $table->json('aliases')->nullable()->after('translations');
        });
    }

    public function down(): void
    {
        Schema::table('attribute_definitions', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
