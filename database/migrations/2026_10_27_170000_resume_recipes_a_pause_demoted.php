<?php

use App\Models\ProductGalleryRecipe;
use Illuminate\Database\Migrations\Migration;

/**
 * A pause (CAPTCHA, rate limit, browser fault) used to demote a proven recipe
 * to "learning", so its site paid for training on every later request (live
 * #237/#265: dell.com after one CAPTCHA). A pause no longer demotes; the
 * recipes it already demoted resume, and their next run replays them as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProductGalleryRecipe::query()
            ->where('status', 'learning')
            ->where('success_count', '>', 0)
            ->whereIn('last_failure_kind', ProductGalleryRecipe::PAUSING_FAILURES)
            ->get()
            ->reject(fn (ProductGalleryRecipe $recipe): bool => $recipe->source_blocked
                || empty($recipe->recipe['collect_selectors'] ?? []))
            ->each(fn (ProductGalleryRecipe $recipe) => $recipe->update(['status' => 'active']));
    }

    public function down(): void
    {
        // The demotion was the defect; there is nothing to restore.
    }
};
