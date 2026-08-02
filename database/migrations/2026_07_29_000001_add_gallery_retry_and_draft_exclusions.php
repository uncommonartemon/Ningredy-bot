<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->json('excluded_gallery_source_urls')->nullable()->after('image_urls');
            $table->json('excluded_gallery_image_urls')->nullable()->after('excluded_gallery_source_urls');
            $table->json('excluded_gallery_hashes')->nullable()->after('excluded_gallery_image_urls');
        });

        Schema::table('product_gallery_recipes', function (Blueprint $table): void {
            $table->string('last_failure_kind', 40)->nullable()->after('last_error');
            $table->timestamp('retry_after')->nullable()->after('last_failure_kind');
        });
    }

    public function down(): void
    {
        Schema::table('product_gallery_recipes', function (Blueprint $table): void {
            $table->dropColumn(['last_failure_kind', 'retry_after']);
        });

        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->dropColumn([
                'excluded_gallery_source_urls',
                'excluded_gallery_image_urls',
                'excluded_gallery_hashes',
            ]);
        });
    }
};
