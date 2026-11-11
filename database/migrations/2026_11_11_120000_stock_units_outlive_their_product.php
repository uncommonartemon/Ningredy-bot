<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The books are a history: a sold laptop is deleted from the catalog, and its sale must stay.
 * Before, deleting a product took its units with it, so a month's sales shrank whenever a
 * sold-out product was removed. A unit now carries its product's name and currency itself and
 * loses only the link when the product goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_units', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
        });
        Schema::table('stock_units', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_variant_id')->nullable()->change();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $table->string('product_title')->nullable();
            $table->string('currency', 3)->nullable();
        });

        DB::table('stock_units')->update([
            'product_title' => DB::raw('(select products.title from product_variants'
                .' join products on products.id = product_variants.product_id'
                .' where product_variants.id = stock_units.product_variant_id)'),
            'currency' => DB::raw('(select product_variants.currency from product_variants'
                .' where product_variants.id = stock_units.product_variant_id)'),
        ]);
    }

    public function down(): void
    {
        // A unit whose product is gone has nothing to be tied to again.
        DB::table('stock_units')->whereNull('product_variant_id')->delete();
        Schema::table('stock_units', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
        });
        Schema::table('stock_units', function (Blueprint $table): void {
            $table->dropColumn(['product_title', 'currency']);
        });
        Schema::table('stock_units', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_variant_id')->nullable(false)->change();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
        });
    }
};
