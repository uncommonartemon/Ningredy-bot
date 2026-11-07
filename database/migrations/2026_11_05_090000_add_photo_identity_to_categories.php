<?php

use App\Models\Category;
use App\Services\Products\ProductPhotoIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What identifies a product in a photograph is the category's choice (operator,
 * 2026-11-04): a laptop by its page and body, a component by the markings on
 * the part itself. Component categories also search galleries the way laptop
 * categories do (Playwright first): the static pictures of a reseller page
 * carried its "related products" into the card (live #380, #384).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('photo_identity', 16)->default(ProductPhotoIdentity::PAGE)->after('gallery_search_strategy');
            $table->text('photo_identity_hint')->nullable()->after('photo_identity');
        });

        DB::table('categories')->where('product_type_affinity', 'component')->update([
            'photo_identity' => ProductPhotoIdentity::LABEL,
            'photo_identity_hint' => Category::COMPONENT_PHOTO_IDENTITY_HINT,
        ]);
        DB::table('categories')->where('product_type_affinity', 'component')
            ->where('gallery_search_strategy', Category::GALLERY_SEARCH_VISION_FIRST)
            ->update(['gallery_search_strategy' => Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn(['photo_identity', 'photo_identity_hint']);
        });
    }
};
