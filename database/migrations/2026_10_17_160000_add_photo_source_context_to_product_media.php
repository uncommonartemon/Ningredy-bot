<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['product_drafts', 'product_draft_media', 'product_media'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                // Null means legacy/unrecorded provenance, never exact-photo confirmation.
                $table->json('photo_source_context')->nullable();
            });
        }
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->json('card_repair_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->dropColumn('card_repair_state');
        });
        foreach (['product_drafts', 'product_draft_media', 'product_media'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('photo_source_context');
            });
        }
    }
};
