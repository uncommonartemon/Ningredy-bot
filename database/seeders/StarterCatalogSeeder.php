<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog's starter dictionaries: the categories the bot sorts every product
 * into and the characteristics its cards are built from. Installation creates them
 * through migrations; a cleanup of the dictionaries removed them for good, and with
 * no category the product search could not answer at all (live 2026-11-01: on
 * another machine every search died in one second after "Общая очистка" with
 * "справочники"). Only what is missing is added; nothing an operator edited changes.
 */
class StarterCatalogSeeder extends Seeder
{
    /** Category slug => the starter row, as tuned on the operator's machine. */
    public const CATEGORIES = [
        'laptops' => ['name' => 'Ноутбуки', 'icon' => 'laptop', 'sort_order' => 1, 'gallery_search_strategy' => 'playwright_first',
            'minimum_verified_images' => 3, 'product_search_hint' => null, 'minimum_image_width' => null, 'product_type_affinity' => 'laptop'],
        'computers' => ['name' => 'Компьютеры', 'icon' => 'computer', 'sort_order' => 2, 'gallery_search_strategy' => 'playwright_first',
            'minimum_verified_images' => 3, 'product_search_hint' => null, 'minimum_image_width' => null, 'product_type_affinity' => 'desktop'],
        'components' => ['name' => 'Комплектующие', 'icon' => 'component', 'sort_order' => 3, 'gallery_search_strategy' => 'vision_first',
            'minimum_verified_images' => 1, 'product_search_hint' => 'Без фото коробки', 'minimum_image_width' => 500, 'product_type_affinity' => 'component'],
        'other-tech' => ['name' => 'Другая техника', 'icon' => 'devices', 'sort_order' => 4, 'gallery_search_strategy' => 'auto',
            'minimum_verified_images' => 3, 'product_search_hint' => null, 'minimum_image_width' => null, 'product_type_affinity' => 'other'],
    ];

    /** The starter categories in the shop's languages (operator, 2026-11-01: the shop speaks Czech and Ukrainian). */
    private const CATEGORY_NAMES = [
        'laptops' => ['cs' => 'Notebooky', 'uk' => 'Ноутбуки', 'en' => 'Laptops'],
        'computers' => ['cs' => 'Počítače', 'uk' => 'Комп’ютери', 'en' => 'Computers'],
        'components' => ['cs' => 'Komponenty', 'uk' => 'Комплектуючі', 'en' => 'Components'],
        'other-tech' => ['cs' => 'Ostatní technika', 'uk' => 'Інша техніка', 'en' => 'Other electronics'],
    ];

    /** Attribute key => the starter definition (create_product_attributes_table). */
    private const ATTRIBUTES = [
        'cpu' => ['label' => 'Процессор', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 10],
        'gpu' => ['label' => 'Видеокарта', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 20],
        'ram' => ['label' => 'Оперативная память', 'data_type' => 'number', 'default_unit' => 'GB', 'sort_order' => 30],
        'storage' => ['label' => 'Накопитель', 'data_type' => 'number', 'default_unit' => 'GB', 'sort_order' => 40],
        'display' => ['label' => 'Дисплей', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 50],
        'screen_size' => ['label' => 'Диагональ', 'data_type' => 'number', 'default_unit' => 'inch', 'sort_order' => 60],
        'refresh_rate' => ['label' => 'Частота экрана', 'data_type' => 'number', 'default_unit' => 'Hz', 'sort_order' => 70],
    ];

    /** The defaults laptops open with (add_default_category_attributes). */
    private const LAPTOP_DEFAULTS = ['gpu', 'cpu', 'display'];

    public function run(): void
    {
        $now = now();
        $createdCategories = [];
        foreach (self::CATEGORIES as $slug => $row) {
            if (DB::table('categories')->where('slug', $slug)->exists()) {
                continue;
            }
            $columns = array_filter($row, fn (string $column): bool => Schema::hasColumn('categories', $column), ARRAY_FILTER_USE_KEY);
            $createdCategories[$slug] = DB::table('categories')->insertGetId([...$columns, 'slug' => $slug, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now]);
        }
        if (Schema::hasTable('category_translations')) {
            foreach (self::CATEGORY_NAMES as $slug => $names) {
                $categoryId = DB::table('categories')->where('slug', $slug)->value('id');
                foreach ($categoryId === null ? [] : $names as $locale => $name) {
                    if (! DB::table('category_translations')->where('category_id', $categoryId)->where('locale', $locale)->exists()) {
                        DB::table('category_translations')->insert(['category_id' => $categoryId, 'locale' => $locale, 'name' => $name,
                            'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }
        }
        if (! Schema::hasTable('attribute_definitions')) {
            return;
        }
        foreach (self::ATTRIBUTES as $key => $row) {
            if (! DB::table('attribute_definitions')->where('key', $key)->exists()) {
                DB::table('attribute_definitions')->insert([...$row, 'key' => $key, 'is_filterable' => true, 'is_variant' => true,
                    'created_at' => $now, 'updated_at' => $now]);
            }
        }
        if ($createdCategories === [] || ! Schema::hasTable('attribute_definition_category')) {
            return;
        }
        // A category created here gets the starter characteristics; one that already
        // existed keeps whatever the operator set up for it.
        $definitions = DB::table('attribute_definitions')->whereIn('key', array_keys(self::ATTRIBUTES))->orderBy('sort_order')->pluck('id', 'key');
        $hasDefault = Schema::hasColumn('attribute_definition_category', 'is_default');
        foreach ($createdCategories as $slug => $categoryId) {
            foreach ($definitions->keys()->values() as $sort => $key) {
                DB::table('attribute_definition_category')->insert([
                    'attribute_definition_id' => $definitions[$key], 'category_id' => $categoryId, 'sort_order' => $sort,
                    ...($hasDefault ? ['is_default' => $slug === 'laptops' && in_array($key, self::LAPTOP_DEFAULTS, true)] : []),
                ]);
            }
        }
    }
}
