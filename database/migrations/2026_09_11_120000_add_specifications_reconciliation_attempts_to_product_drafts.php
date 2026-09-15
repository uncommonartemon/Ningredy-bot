<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('specifications_reconciliation_attempts')->default(0)
                ->after('specifications_reconciled_source_url');
        });
    }

    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->dropColumn('specifications_reconciliation_attempts');
        });
    }
};
