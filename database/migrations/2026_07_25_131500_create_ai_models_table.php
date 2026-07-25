<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('model');
            $table->string('label');
            $table->decimal('input_per_million', 12, 6);
            $table->decimal('cached_input_per_million', 12, 6);
            $table->decimal('output_per_million', 12, 6);
            $table->text('source_url');
            $table->date('pricing_checked_at');
            $table->boolean('is_available')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'model']);
            $table->index(['provider', 'is_enabled']);
        });

        $checkedAt = (string) config('ai_model_catalog.pricing_checked_at', now()->toDateString());
        $now = now();
        $rows = [];

        foreach ((array) config('ai_model_catalog.providers', []) as $provider => $providerData) {
            foreach ((array) ($providerData['models'] ?? []) as $model => $details) {
                $rows[] = [
                    'provider' => $provider,
                    'model' => $model,
                    'label' => $details['label'] ?? $model,
                    'input_per_million' => $details['input_per_million'],
                    'cached_input_per_million' => $details['cached_input_per_million'],
                    'output_per_million' => $details['output_per_million'],
                    'source_url' => $details['source_url'],
                    'pricing_checked_at' => $checkedAt,
                    'is_available' => true,
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('ai_models')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
