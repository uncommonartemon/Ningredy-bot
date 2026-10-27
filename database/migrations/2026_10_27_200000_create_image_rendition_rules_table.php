<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a site keeps the larger rendition of a thumbnail is data, not code: the
 * rules start from the ones written by hand so far, each site's outcomes are
 * counted, a rule that keeps missing on a site stops there, and the agent
 * learns a site's own rule when none fits (operator, 2026-10-27: "if a site
 * changes something, can the agent change the rule itself?").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_rendition_rules', function (Blueprint $table): void {
            $table->id();
            // The image host the rule belongs to (a suffix match); null for any site.
            $table->string('host')->nullable()->index();
            $table->string('find', 500);
            $table->string('flags', 8)->default('');
            $table->string('replace', 500);
            $table->string('origin', 20)->default('seed');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('position')->default(100);
            $table->text('note')->nullable();
            $table->string('example_from', 2048)->nullable();
            $table->string('example_to', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('image_rendition_rule_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('image_rendition_rule_id')->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('misses')->default(0);
            $table->unsignedInteger('consecutive_misses')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamp('last_miss_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
            $table->unique(['image_rendition_rule_id', 'host']);
        });

        $seeds = json_decode((string) file_get_contents(database_path('data/rendition-rules.json')), true) ?: [];
        foreach (array_values($seeds) as $index => $rule) {
            DB::table('image_rendition_rules')->insert([
                'host' => null, 'find' => $rule['find'], 'flags' => $rule['flags'] ?? '', 'replace' => $rule['replace'],
                'origin' => 'seed', 'status' => 'active', 'position' => ($index + 1) * 10, 'note' => $rule['note'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('image_rendition_rule_outcomes');
        Schema::dropIfExists('image_rendition_rules');
    }
};
