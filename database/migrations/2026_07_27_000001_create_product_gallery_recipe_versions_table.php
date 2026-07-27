<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_gallery_recipe_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_gallery_recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain')->index();
            $table->text('product_url');
            $table->string('trigger')->index();
            $table->string('status')->default('training')->index();
            $table->string('provider');
            $table->string('model');
            $table->json('previous_recipe')->nullable();
            $table->json('recipe')->nullable();
            $table->json('scout')->nullable();
            $table->json('result')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_gallery_recipe_versions');
    }
};
