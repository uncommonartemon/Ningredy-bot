<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('data_type')->default('text');
            $table->string('default_unit', 24)->nullable();
            $table->boolean('is_filterable')->default(true)->index();
            $table->boolean('is_variant')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('attribute_definition_category', function (Blueprint $table) {
            $table->foreignId('attribute_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['attribute_definition_id', 'category_id'], 'attribute_category_primary');
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('value');
            $table->string('normalized_value')->nullable();
            $table->decimal('numeric_value', 14, 4)->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->string('unit', 24)->nullable();
            $table->timestamps();

            $table->unique(['attribute_definition_id', 'product_id'], 'attribute_product_unique');
            $table->unique(['attribute_definition_id', 'product_variant_id'], 'attribute_variant_unique');
            $table->index(['attribute_definition_id', 'normalized_value'], 'attribute_text_lookup');
            $table->index(['attribute_definition_id', 'numeric_value'], 'attribute_number_lookup');
        });

        $now = now();
        $definitions = [
            ['key' => 'cpu', 'label' => 'Процессор', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 10],
            ['key' => 'gpu', 'label' => 'Видеокарта', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 20],
            ['key' => 'ram', 'label' => 'Оперативная память', 'data_type' => 'number', 'default_unit' => 'GB', 'sort_order' => 30],
            ['key' => 'storage', 'label' => 'Накопитель', 'data_type' => 'number', 'default_unit' => 'GB', 'sort_order' => 40],
            ['key' => 'display', 'label' => 'Дисплей', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 50],
            ['key' => 'screen_size', 'label' => 'Диагональ', 'data_type' => 'number', 'default_unit' => 'inch', 'sort_order' => 60],
            ['key' => 'refresh_rate', 'label' => 'Частота экрана', 'data_type' => 'number', 'default_unit' => 'Hz', 'sort_order' => 70],
        ];

        foreach ($definitions as $definition) {
            DB::table('attribute_definitions')->insert([
                ...$definition,
                'is_filterable' => true,
                'is_variant' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $categoryIds = DB::table('categories')->pluck('id');
        $definitionIds = DB::table('attribute_definitions')->pluck('id');

        foreach ($categoryIds as $categoryId) {
            foreach ($definitionIds as $sort => $definitionId) {
                DB::table('attribute_definition_category')->insert([
                    'attribute_definition_id' => $definitionId,
                    'category_id' => $categoryId,
                    'sort_order' => $sort,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attribute_definition_category');
        Schema::dropIfExists('attribute_definitions');
    }
};
