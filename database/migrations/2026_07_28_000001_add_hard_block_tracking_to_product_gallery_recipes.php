<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_gallery_recipes', function (Blueprint $table): void {
            $table->unsignedInteger('consecutive_hard_blocks')->default(0)->after('failure_count');
            $table->json('hard_block_urls')->nullable()->after('consecutive_hard_blocks');
        });
    }

    public function down(): void
    {
        Schema::table('product_gallery_recipes', function (Blueprint $table): void {
            $table->dropColumn(['consecutive_hard_blocks', 'hard_block_urls']);
        });
    }
};
