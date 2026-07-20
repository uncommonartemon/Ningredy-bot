<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('country')->nullable()->index();
            $table->string('website_url')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('canonical_key')->unique();
            $table->string('product_type')->default('other')->index();
            $table->string('status')->default('published')->index();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('model')->nullable()->index();
            $table->text('description')->nullable();
            $table->decimal('confidence', 5, 4)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'is_active', 'published_at']);
            $table->index(['category_id', 'status', 'is_active']);
            $table->index(['brand_id', 'status', 'is_active']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint');
            $table->string('name')->nullable();
            $table->string('sku')->nullable()->unique();
            $table->string('mpn')->nullable()->index();
            $table->string('gtin')->nullable()->unique();
            $table->string('color')->nullable()->index();
            $table->string('condition')->default('new')->index();
            $table->decimal('price', 12, 2)->nullable()->index();
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->char('currency', 3)->default('CZK');
            $table->string('stock_status')->default('unknown')->index();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['product_id', 'fingerprint']);
            $table->index(['product_id', 'is_active', 'is_default']);
        });

        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type')->default('image');
            $table->text('url');
            $table->text('source_url')->nullable();
            $table->string('alt')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('product_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_draft_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('url');
            $table->string('domain')->nullable()->index();
            $table->string('source_type')->default('web')->index();
            $table->timestamp('retrieved_at')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamps();
        });

        Schema::table('product_drafts', function (Blueprint $table) {
            $table->foreignId('approved_product_id')->nullable()->after('rejection_reason')->constrained('products')->nullOnDelete();
            $table->foreignId('approved_variant_id')->nullable()->after('approved_product_id')->constrained('product_variants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_variant_id');
            $table->dropConstrainedForeignId('approved_product_id');
        });

        Schema::dropIfExists('product_sources');
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
    }
};
