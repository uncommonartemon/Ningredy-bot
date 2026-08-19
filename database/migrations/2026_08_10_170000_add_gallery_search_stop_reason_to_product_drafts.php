<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->string('gallery_search_stop_reason', 40)->nullable()->after('gallery_notes');
        });
    }

    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->dropColumn('gallery_search_stop_reason');
        });
    }
};
