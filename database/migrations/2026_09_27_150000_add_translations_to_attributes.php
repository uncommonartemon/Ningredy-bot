<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalog text is written in English; these hold its translations,
        // keyed by locale ({"cs": "..."}). A missing locale falls back to the
        // English value, never to another translation.
        Schema::table('attribute_definitions', function (Blueprint $table): void {
            $table->json('translations')->nullable();
        });
        Schema::table('attribute_values', function (Blueprint $table): void {
            $table->json('translations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attribute_values', function (Blueprint $table): void {
            $table->dropColumn('translations');
        });
        Schema::table('attribute_definitions', function (Blueprint $table): void {
            $table->dropColumn('translations');
        });
    }
};
