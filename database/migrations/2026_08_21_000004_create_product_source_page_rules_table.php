<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_source_page_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->index();
            $table->string('path_hash', 64);
            $table->text('path');
            $table->text('sample_url');
            $table->string('layout_fingerprint', 64)->nullable()->index();
            $table->string('page_kind', 40)->index();
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->decimal('confidence', 5, 4);
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('hit_count')->default(1);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'path_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_source_page_rules');
    }
};
