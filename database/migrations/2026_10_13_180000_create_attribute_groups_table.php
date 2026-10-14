<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->json('translations')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('highlight_in_filters')->default(false);
            $table->timestamps();
        });

        Schema::table('attribute_definitions', function (Blueprint $table): void {
            $table->foreignId('attribute_group_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attribute_definitions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('attribute_group_id');
        });
        Schema::dropIfExists('attribute_groups');
    }
};
