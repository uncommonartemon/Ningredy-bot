<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->timestamps();

            $table->unique(['category_id', 'locale']);
            $table->index(['locale', 'name']);
        });

        $categories = [
            'laptops' => ['en' => 'Laptops', 'cs' => 'Notebooky', 'uk' => 'Ноутбуки'],
            'computers' => ['en' => 'Desktop PCs', 'cs' => 'Stolní počítače', 'uk' => 'Настільні комп’ютери'],
            'components' => ['en' => 'Components', 'cs' => 'Komponenty', 'uk' => 'Комплектуючі'],
            'other-tech' => ['en' => 'Other electronics', 'cs' => 'Ostatní elektronika', 'uk' => 'Інша електроніка'],
        ];

        foreach ($categories as $slug => $translations) {
            $categoryId = DB::table('categories')->where('slug', $slug)->value('id');

            if (! $categoryId) {
                continue;
            }

            DB::table('categories')->where('id', $categoryId)->update([
                'name' => $translations['en'],
                'updated_at' => now(),
            ]);

            foreach ($translations as $locale => $name) {
                DB::table('category_translations')->insert([
                    'category_id' => $categoryId,
                    'locale' => $locale,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $legacyNames = [
            'laptops' => 'Ноутбуки',
            'computers' => 'Компьютеры',
            'components' => 'Комплектующие',
            'other-tech' => 'Другая техника',
        ];

        foreach ($legacyNames as $slug => $name) {
            DB::table('categories')->where('slug', $slug)->update(['name' => $name]);
        }

        Schema::dropIfExists('category_translations');
    }
};
