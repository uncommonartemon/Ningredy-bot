<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The column encoded an assumption the application never actually held:
     * that every AI call originates from a Telegram update. Retraining started
     * from the Filament recipe screen (TrainProductGalleryRecipe) runs with no
     * update at all, and ProductGalleryRecipeTrainer worked around the NOT NULL
     * by simply not recording those runs - so operator-triggered training was
     * invisible in the cost/audit trail. The gallery agent's own Vision tool
     * then hit the same constraint head-on and failed at the database level.
     *
     * Making it nullable both unblocks that path and lets non-Telegram AI calls
     * be audited like every other one.
     */
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->foreignId('telegram_update_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows written while the column was nullable have no update to point
        // at, so they are detached rather than blocking the rollback.
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->foreignId('telegram_update_id')->nullable(false)->change();
        });
    }
};
