<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            // The stored outcome of the gallery's own completeness check
            // (enough verified photos, per the category's own target), set
            // only at the same points gallery_status itself is decided.
            // resumeOutstandingReconciliation() reads this instead of
            // re-deriving "is the gallery done" from a live media count
            // comparison, which cannot tell a gallery an earlier round
            // actually verified complete from one that merely happens to
            // have enough rows on disk right now.
            $table->boolean('gallery_confirmed_sufficient')->default(false)
                ->after('specifications_reconciliation_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->dropColumn('gallery_confirmed_sufficient');
        });
    }
};
