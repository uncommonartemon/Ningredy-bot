<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->text('research_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->dropColumn('research_notes');
        });
    }
};
