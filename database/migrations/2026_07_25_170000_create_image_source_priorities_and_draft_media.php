<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_source_priorities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('domain')->unique();
            $table->json('aliases')->nullable();
            $table->string('source_type', 32);
            $table->unsignedInteger('priority')->default(500);
            $table->boolean('is_enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'priority']);
        });

        $now = now();
        DB::table('image_source_priorities')->insert([
            [
                'name' => 'Amazon',
                'domain' => 'amazon.com',
                'aliases' => json_encode([
                    'amazon.ca', 'amazon.co.uk', 'amazon.de', 'amazon.fr', 'amazon.it',
                    'amazon.es', 'amazon.nl', 'amazon.pl', 'amazon.se', 'amazon.com.be',
                    'media-amazon.com', 'images-amazon.com', 'ssl-images-amazon.com',
                ]),
                'source_type' => 'marketplace',
                'priority' => 1000,
                'is_enabled' => true,
                'notes' => 'Главный источник галерей. Обязательно проверять точный вариант, ASIN и цвет.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['name' => 'Best Buy', 'domain' => 'bestbuy.com', 'aliases' => null, 'source_type' => 'retailer', 'priority' => 970, 'is_enabled' => true, 'notes' => 'Электроника и бытовая техника.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'B&H Photo', 'domain' => 'bhphotovideo.com', 'aliases' => null, 'source_type' => 'retailer', 'priority' => 960, 'is_enabled' => true, 'notes' => 'Компьютеры, камеры и профессиональная электроника.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Newegg', 'domain' => 'newegg.com', 'aliases' => json_encode(['neweggimages.com']), 'source_type' => 'marketplace', 'priority' => 950, 'is_enabled' => true, 'notes' => 'Комплектующие и готовые компьютеры.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Walmart', 'domain' => 'walmart.com', 'aliases' => null, 'source_type' => 'marketplace', 'priority' => 930, 'is_enabled' => true, 'notes' => 'Широкий универсальный каталог.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Target', 'domain' => 'target.com', 'aliases' => null, 'source_type' => 'retailer', 'priority' => 910, 'is_enabled' => true, 'notes' => 'Бытовые товары и электроника.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Micro Center', 'domain' => 'microcenter.com', 'aliases' => null, 'source_type' => 'retailer', 'priority' => 900, 'is_enabled' => true, 'notes' => 'Компьютеры и комплектующие.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Adorama', 'domain' => 'adorama.com', 'aliases' => null, 'source_type' => 'retailer', 'priority' => 890, 'is_enabled' => true, 'notes' => 'Фото, видео и электроника.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Costco', 'domain' => 'costco.com', 'aliases' => null, 'source_type' => 'retailer', 'priority' => 870, 'is_enabled' => true, 'notes' => 'Крупная розничная сеть.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Alza', 'domain' => 'alza.cz', 'aliases' => json_encode(['alza.de', 'alza.sk', 'alza.at']), 'source_type' => 'retailer', 'priority' => 860, 'is_enabled' => true, 'notes' => 'Приоритетный магазин для Чехии и ЕС.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'MediaMarkt', 'domain' => 'mediamarkt.de', 'aliases' => json_encode(['mediamarkt.com', 'mediamarkt.cz']), 'source_type' => 'retailer', 'priority' => 850, 'is_enabled' => true, 'notes' => 'Крупная европейская сеть электроники.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Saturn', 'domain' => 'saturn.de', 'aliases' => null, 'source_type' => 'retailer', 'priority' => 840, 'is_enabled' => true, 'notes' => 'Европейская сеть электроники.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'eBay', 'domain' => 'ebay.com', 'aliases' => json_encode(['ebay.de', 'ebay.co.uk']), 'source_type' => 'marketplace', 'priority' => 600, 'is_enabled' => true, 'notes' => 'Резерв: фотографии продавцов требуют строгой проверки.', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->text('primary_source_url')->nullable()->after('sources');
            $table->text('official_source_url')->nullable()->after('primary_source_url');
            $table->timestamp('images_staged_at')->nullable()->after('image_urls');
        });

        Schema::create('product_draft_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_draft_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->text('source_url')->nullable();
            $table->string('role', 32)->default('detail');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum', 64);
            $table->string('verification_status', 32)->default('verified');
            $table->decimal('verification_score', 5, 4)->nullable();
            $table->string('verification_model')->nullable();
            $table->text('verification_notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['product_draft_id', 'checksum']);
            $table->index(['product_draft_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_draft_media');

        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->dropColumn(['primary_source_url', 'official_source_url', 'images_staged_at']);
        });

        Schema::dropIfExists('image_source_priorities');
    }
};
