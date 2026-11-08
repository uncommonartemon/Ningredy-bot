<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The characteristics a product's catalog card shows, chosen by a model when the product
 * is formed (operator, 2026-11-08). Null means not chosen yet; an empty list means chosen
 * and nothing to show first, so the card keeps the usual order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('card_attribute_keys')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('card_attribute_keys');
        });
    }
};
