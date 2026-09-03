<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_gallery_recipes', function (Blueprint $table): void {
            $table->json('compatible_path_patterns')->nullable()->after('path_pattern');
        });
    }

    public function down(): void
    {
        Schema::table('product_gallery_recipes', function (Blueprint $table): void {
            $table->dropColumn('compatible_path_patterns');
        });
    }
};
