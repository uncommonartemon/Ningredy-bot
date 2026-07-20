<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('categories')->insert([
            ['name' => 'Ноутбуки', 'slug' => 'laptops', 'icon' => 'laptop', 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Компьютеры', 'slug' => 'computers', 'icon' => 'computer', 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Комплектующие', 'slug' => 'components', 'icon' => 'component', 'sort_order' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Другая техника', 'slug' => 'other-tech', 'icon' => 'devices', 'sort_order' => 40, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('categories')->whereIn('slug', ['laptops', 'computers', 'components', 'other-tech'])->delete();
    }
};
