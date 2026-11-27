<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A brand's country in the shop's languages (decision of 2026-11-27: a buyer reads a card only in
 * the shop's languages). The country is typed by hand in the admin and stood on product pages and
 * in the filters as written; the names' schedule fills these from it. Existing brands start with
 * none and are named at the next round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->json('country_translations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('country_translations');
        });
    }
};
