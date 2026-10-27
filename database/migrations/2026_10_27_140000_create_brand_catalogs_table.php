<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each brand keeps its own catalog, as data the operator edits: how to
 * find a code there with web search, an address that opens a product by code,
 * or a public JSON API with the fields to read. One generic reader serves every
 * row; a new brand is a new row, never new code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_catalogs', function (Blueprint $table): void {
            $table->id();
            $table->string('brand', 100)->index();
            $table->json('aliases')->nullable();
            $table->string('catalog_url', 1024)->nullable();
            $table->string('search_hint', 500)->nullable();
            $table->string('lookup_template', 1024)->nullable();
            $table->string('api_template', 1024)->nullable();
            $table->json('response_map')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('misses')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_result', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_catalogs');
    }
};
