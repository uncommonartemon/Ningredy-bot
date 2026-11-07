<?php

use Database\Seeders\ReferenceSiteSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference sites per kind of product, as data the operator and the bot edit
 * (operator, 2026-11-02: «добавить таблицу с запросами + ссылки… использовать
 * при поиске ссд и хард диск»). One generic reader serves every row; a new
 * site is a new row, never new code. The starting rows arrive once, here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 255)->index();
            $table->string('applies_to', 500);
            $table->boolean('use_for_facts')->default(true);
            $table->boolean('use_for_photos')->default(false);
            $table->string('access', 16)->default('bot');
            $table->string('search_hint', 500)->nullable();
            $table->string('link_template', 1024)->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('misses')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_result', 255)->nullable();
            $table->timestamps();
        });

        (new ReferenceSiteSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sites');
    }
};
