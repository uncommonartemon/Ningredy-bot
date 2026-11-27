<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the server placed a found product and why (decision of 2026-11-25): what the search asked
 * for, the node it went to, and whether its kind is still open. The draft's card shows it before
 * «Добавить». Drafts made before this have none; their card reads the category as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_drafts', function (Blueprint $table) {
            $table->json('category_placement')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table) {
            $table->dropColumn('category_placement');
        });
    }
};
