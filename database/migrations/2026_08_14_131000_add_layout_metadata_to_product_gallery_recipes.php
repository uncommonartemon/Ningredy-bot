<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_gallery_recipes', function (Blueprint $table): void {
            $table->string('region', 32)->nullable()->after('path_pattern');
            $table->string('sample_path', 1024)->nullable()->after('region');
            $table->string('layout_fingerprint', 64)->nullable()->after('sample_path');
            $table->string('last_observed_layout_fingerprint', 64)->nullable()->after('layout_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('product_gallery_recipes', function (Blueprint $table): void {
            $table->dropColumn([
                'region', 'sample_path', 'layout_fingerprint', 'last_observed_layout_fingerprint',
            ]);
        });
    }
};
