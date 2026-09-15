<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_source_page_evidence', function (Blueprint $table): void {
            // What the fetched page itself said it was (title/h1/meta/
            // JSON-LD, or the browser's canonical/og:url/JSON-LD Product
            // fields) - returned to the agent at fetch time but, until now,
            // never saved, so a later attempt reading this row back had the
            // text but not the one thing that says whether it belongs to
            // this product at all.
            $table->text('identity_evidence')->nullable()->after('specification_text');
        });
    }

    public function down(): void
    {
        Schema::table('product_source_page_evidence', function (Blueprint $table): void {
            $table->dropColumn('identity_evidence');
        });
    }
};
