<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_source_page_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_draft_id')->constrained()->cascadeOnDelete();
            $table->string('url_hash', 64);
            $table->text('url');
            $table->text('specification_text');
            $table->string('captured_via', 20);
            $table->timestamps();

            $table->unique(['product_draft_id', 'url_hash']);
        });

        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->text('specifications_reconciled_source_url')->nullable()->after('primary_source_url');
        });
    }

    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->dropColumn('specifications_reconciled_source_url');
        });

        Schema::dropIfExists('product_source_page_evidence');
    }
};
