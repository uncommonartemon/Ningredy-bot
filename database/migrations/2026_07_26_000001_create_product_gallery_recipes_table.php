<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_gallery_recipes', function (Blueprint $table): void {
            $table->id();
            $table->string('domain');
            $table->string('path_pattern')->default('*');
            $table->json('recipe')->nullable();
            $table->string('status')->default('learning');
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['domain', 'path_pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_gallery_recipes');
    }
};
