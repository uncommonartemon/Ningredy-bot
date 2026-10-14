<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_sources', fn (Blueprint $table) => $table->string('title', 500)->change());
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->string('condition')->default('new');
            $table->json('specification_evidence')->nullable();
            $table->json('translations')->nullable();
        });
        Schema::table('products', fn (Blueprint $table) => $table->json('translations')->nullable());
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->json('translations')->nullable();
            $table->dropUnique(['sku']);
            $table->dropUnique(['gtin']);
            $table->unique(['sku', 'condition']);
            $table->unique(['gtin', 'condition']);
        });
    }

    public function down(): void
    {
        // Restoring the old constraints would discard valid offers of the
        // same SKU in different conditions. Fail safely on such data.
        foreach (['sku', 'gtin'] as $column) {
            if (DB::table('product_variants')->whereNotNull($column)
                ->groupBy($column)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Cannot roll back: multiple conditions share '.$column.'. No data was deleted.');
            }
        }
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique(['sku', 'condition']);
            $table->dropUnique(['gtin', 'condition']);
            $table->unique('sku');
            $table->unique('gtin');
            $table->dropColumn('translations');
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('translations'));
        Schema::table('product_drafts', fn (Blueprint $table) => $table->dropColumn(['condition', 'specification_evidence', 'translations']));
    }
};
