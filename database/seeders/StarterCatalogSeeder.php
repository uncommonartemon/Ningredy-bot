<?php

namespace Database\Seeders;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Services\Products\ProductPhotoIdentity;
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
        'components' => ['name' => 'Комплектующие', 'icon' => 'component', 'sort_order' => 3, 'gallery_search_strategy' => 'playwright_first',
            'minimum_verified_images' => 1, 'product_search_hint' => 'Без фото коробки', 'minimum_image_width' => 500, 'product_type_affinity' => 'component',
            'photo_identity' => 'label', 'photo_identity_hint' => Category::COMPONENT_PHOTO_IDENTITY_HINT],
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

    /**
     * The kinds of components the colleague photographs (operator, 2026-11-04:
     * «подкатегории? да»), each under «Комплектующие» with the characteristics a
     * card of that kind must carry. Research is told the keys; what it does not
     * find, the card says is missing instead of staying silent.
     */
    public const COMPONENT_KINDS = [
        'ssd' => ['name' => 'SSD-накопители', 'sort_order' => 31,
            'names' => ['cs' => 'SSD disky', 'uk' => 'SSD-накопичувачі', 'en' => 'SSD drives'],
            'product_search_hint' => 'Без фото коробки. Код на наклейке — полный P/N производителя (например HFM512GDJTNG-8310A, MZVLQ512HBLU-00B00); OEM-накопители из ноутбуков ищи по полному коду, суффикс отличает партию.',
            'photo_identity_hint' => 'На наклейке накопителя: P/N (model), объём, интерфейс; виден форм-фактор — длина платы M.2 (2230/2242/2280) или корпус 2.5". Код на наклейке — это и есть товар.',
            'attributes' => ['mpn', 'storage', 'form_factor', 'interface', 'endurance_tbw', 'health_percent', 'power_on_hours', 'data_written_tb'],
            'required' => ['mpn', 'storage', 'form_factor', 'interface']],
        'hdd' => ['name' => 'Жёсткие диски', 'sort_order' => 32,
            'names' => ['cs' => 'Pevné disky', 'uk' => 'Жорсткі диски', 'en' => 'Hard drives'],
            'product_search_hint' => 'Без фото коробки. На наклейке диска — модель (ST1000LM035, WD10SPZX), объём и P/N; ищи по модели, затем по P/N.',
            'photo_identity_hint' => 'На наклейке диска: модель, объём, P/N, дата; виден форм-фактор 2.5" или 3.5". Другая модель на наклейке — другой товар.',
            'attributes' => ['mpn', 'storage', 'form_factor', 'interface', 'rpm', 'health_percent', 'power_on_hours'],
            'required' => ['mpn', 'storage', 'form_factor', 'interface', 'rpm']],
        'ram' => ['name' => 'Оперативная память', 'sort_order' => 33,
            'names' => ['cs' => 'Operační paměť', 'uk' => 'Оперативна пам’ять', 'en' => 'Memory modules'],
            'product_search_hint' => 'Без фото коробки. На наклейке модуля — P/N (M471A1K43DB1-CTD, MTA8ATF1G64HZ-3G2R1, KVR32S22S8/16), объём, тип DDR и скорость; модули одного объёма разных партий — разные коды.',
            'photo_identity_hint' => 'На наклейке модуля: P/N, объём, DDR4/DDR5, скорость; виден форм-фактор — SO-DIMM (короткий) или DIMM (длинный). Другой P/N на наклейке — другой товар.',
            'attributes' => ['mpn', 'ram', 'memory_type', 'data_rate', 'module_type'],
            'required' => ['mpn', 'ram', 'memory_type', 'data_rate', 'module_type']],
        'cpu' => ['name' => 'Процессоры', 'sort_order' => 34,
            'names' => ['cs' => 'Procesory', 'uk' => 'Процесори', 'en' => 'Processors'],
            'product_search_hint' => 'Без фото коробки (OEM/tray). На крышке процессора — модель (i5-12400F, Ryzen 5 5600) и код S-spec/OPN (SRL5Y, 100-000000065); ищи по модели и коду.',
            'photo_identity_hint' => 'На крышке процессора выгравированы модель и код S-spec/OPN; сокет виден по корпусу (LGA, PGA, BGA). Другая модель или код — другой товар.',
            'attributes' => ['core_count', 'thread_count', 'base_frequency', 'socket', 'tdp', 'mpn'],
            'required' => ['core_count', 'thread_count', 'base_frequency', 'socket']],
        'gpu' => ['name' => 'Видеокарты', 'sort_order' => 35,
            'names' => ['cs' => 'Grafické karty', 'uk' => 'Відеокарти', 'en' => 'Graphics cards'],
            'product_search_hint' => 'Без фото коробки. На наклейке карты — модель и P/N производителя платы (MSI, ASUS, Gigabyte…) и объём видеопамяти; ищи по P/N производителя платы, не только по чипу.',
            'photo_identity_hint' => 'На наклейке карты (сзади платы): модель и P/N производителя платы, серийный номер; видны чип и разъёмы. Карта другого производителя платы или другой модели — другой товар.',
            'attributes' => ['gpu', 'vram', 'memory_type', 'interface', 'mpn'],
            'required' => ['gpu', 'vram', 'memory_type', 'interface']],
    ];

    /**
     * The characteristics of components, created only when a catalog has none
     * with that key (a definition research created earlier keeps its label and
     * type). Health rows are per unit: a used part's wear belongs to its item.
     */
    public const COMPONENT_ATTRIBUTES = [
        'mpn' => ['label' => 'Код производителя (P/N)', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 5,
            'translations' => ['en' => 'Manufacturer part number', 'cs' => 'Číslo dílu výrobce', 'uk' => 'Номер деталі виробника']],
        'form_factor' => ['label' => 'Форм-фактор', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 80,
            'translations' => ['en' => 'Form factor', 'cs' => 'Formát (form factor)', 'uk' => 'Форм-фактор']],
        'interface' => ['label' => 'Интерфейс', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 90,
            'translations' => ['en' => 'Interface', 'cs' => 'Rozhraní', 'uk' => 'Інтерфейс']],
        'memory_type' => ['label' => 'Тип памяти', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 100,
            'translations' => ['en' => 'Memory type', 'cs' => 'Typ paměti', 'uk' => 'Тип пам’яті']],
        'module_type' => ['label' => 'Тип модуля', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 110,
            'translations' => ['en' => 'Module type', 'cs' => 'Typ modulu', 'uk' => 'Тип модуля']],
        'data_rate' => ['label' => 'Скорость памяти', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 120,
            'translations' => ['en' => 'Data rate', 'cs' => 'Datová rychlost', 'uk' => 'Швидкість передавання даних']],
        'rpm' => ['label' => 'Скорость вращения', 'data_type' => 'number', 'default_unit' => 'rpm', 'sort_order' => 130,
            'translations' => ['en' => 'Spindle speed', 'cs' => 'Otáčky', 'uk' => 'Швидкість обертання']],
        'core_count' => ['label' => 'Ядра', 'data_type' => 'number', 'default_unit' => null, 'sort_order' => 140,
            'translations' => ['en' => 'CPU core count', 'cs' => 'Počet jader procesoru', 'uk' => 'Кількість ядер процесора']],
        'thread_count' => ['label' => 'Потоки', 'data_type' => 'number', 'default_unit' => null, 'sort_order' => 150,
            'translations' => ['en' => 'CPU thread count', 'cs' => 'Počet vláken procesoru', 'uk' => 'Кількість потоків процесора']],
        'base_frequency' => ['label' => 'Базовая частота', 'data_type' => 'number', 'default_unit' => 'GHz', 'sort_order' => 160,
            'translations' => ['en' => 'Base frequency', 'cs' => 'Základní frekvence', 'uk' => 'Базова частота']],
        'socket' => ['label' => 'Сокет', 'data_type' => 'text', 'default_unit' => null, 'sort_order' => 170,
            'translations' => ['en' => 'Socket', 'cs' => 'Patice (socket)', 'uk' => 'Сокет']],
        'tdp' => ['label' => 'TDP', 'data_type' => 'number', 'default_unit' => 'W', 'sort_order' => 180,
            'translations' => ['en' => 'Thermal design power', 'cs' => 'Tepelný návrhový výkon', 'uk' => 'Теплова розрахункова потужність']],
        'vram' => ['label' => 'Видеопамять', 'data_type' => 'number', 'default_unit' => 'GB', 'sort_order' => 190,
            'translations' => ['en' => 'Video memory', 'cs' => 'Videopaměť', 'uk' => 'Відеопам’ять']],
        'health_percent' => ['label' => 'Здоровье (SMART)', 'data_type' => 'number', 'default_unit' => '%', 'sort_order' => 200, 'is_variant' => true,
            'translations' => ['en' => 'Health (SMART)', 'cs' => 'Zdraví (SMART)', 'uk' => 'Здоров’я (SMART)']],
        'power_on_hours' => ['label' => 'Наработка', 'data_type' => 'number', 'default_unit' => 'h', 'sort_order' => 210, 'is_variant' => true,
            'translations' => ['en' => 'Power-on hours', 'cs' => 'Odpracované hodiny', 'uk' => 'Напрацювання']],
        'data_written_tb' => ['label' => 'Записано на этом экземпляре', 'data_type' => 'number', 'default_unit' => 'TB', 'sort_order' => 220, 'is_variant' => true,
            'translations' => ['en' => 'Data written on this unit', 'cs' => 'Zapsáno na tomto kuse', 'uk' => 'Записано на цьому екземплярі']],
        'endurance_tbw' => ['label' => 'Ресурс записи (TBW)', 'data_type' => 'number', 'default_unit' => 'TB', 'sort_order' => 195,
            'translations' => ['en' => 'Rated endurance (TBW)', 'cs' => 'Životnost zápisu (TBW)', 'uk' => 'Ресурс запису (TBW)']],
    ];

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
        $this->seedNames(self::CATEGORY_NAMES);
        if (! Schema::hasTable('attribute_definitions')) {
            return;
        }
        foreach (self::ATTRIBUTES as $key => $row) {
            if (! DB::table('attribute_definitions')->where('key', $key)->exists()) {
                DB::table('attribute_definitions')->insert([...$row, 'key' => $key, 'is_filterable' => true, 'is_variant' => true,
                    'created_at' => $now, 'updated_at' => $now]);
            }
        }
        if ($createdCategories !== [] && Schema::hasTable('attribute_definition_category')) {
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
        $this->seedComponentKinds();
    }

    /**
     * The kinds of components under «Комплектующие» and their characteristics.
     * Without the parent category there is nothing to hang them on; a kind the
     * operator removed is not recreated, a kind that exists keeps its rows.
     */
    public function seedComponentKinds(): void
    {
        if (! Schema::hasColumn('categories', 'parent_id') || ! Schema::hasTable('attribute_definitions')) {
            return;
        }
        $parentId = DB::table('categories')->where('slug', 'components')->value('id');
        if ($parentId === null) {
            return;
        }
        $now = now();
        $definitionIds = [];
        foreach ([...self::ATTRIBUTES, ...self::COMPONENT_ATTRIBUTES] as $key => $row) {
            $id = DB::table('attribute_definitions')->where('key', $key)->value('id');
            if ($id === null) {
                $id = DB::table('attribute_definitions')->insertGetId([
                    'key' => $key, 'label' => $row['label'], 'data_type' => $row['data_type'], 'default_unit' => $row['default_unit'],
                    'is_filterable' => AttributeDefinition::filterableByDefault($key), 'is_variant' => $row['is_variant'] ?? false,
                    'sort_order' => $row['sort_order'], 'created_at' => $now, 'updated_at' => $now,
                    ...(isset($row['translations']) && Schema::hasColumn('attribute_definitions', 'translations')
                        ? ['translations' => json_encode($row['translations'], JSON_UNESCAPED_UNICODE)] : []),
                ]);
            }
            $definitionIds[$key] = (int) $id;
        }
        $hasDefault = Schema::hasColumn('attribute_definition_category', 'is_default');
        $names = [];
        foreach (self::COMPONENT_KINDS as $slug => $kind) {
            $names[$slug] = $kind['names'];
            if (DB::table('categories')->where('slug', $slug)->exists()) {
                continue;
            }
            $row = ['name' => $kind['name'], 'icon' => 'component', 'sort_order' => $kind['sort_order'], 'parent_id' => $parentId,
                'gallery_search_strategy' => Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST, 'minimum_verified_images' => 1,
                'minimum_image_width' => 500, 'product_type_affinity' => 'component', 'product_search_hint' => $kind['product_search_hint'],
                'photo_identity' => ProductPhotoIdentity::LABEL, 'photo_identity_hint' => $kind['photo_identity_hint']];
            $columns = array_filter($row, fn (string $column): bool => Schema::hasColumn('categories', $column), ARRAY_FILTER_USE_KEY);
            $categoryId = DB::table('categories')->insertGetId([...$columns, 'slug' => $slug, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now]);
            if (! Schema::hasTable('attribute_definition_category')) {
                continue;
            }
            foreach (array_values($kind['attributes']) as $sort => $key) {
                $required = in_array($key, $kind['required'], true);
                DB::table('attribute_definition_category')->insert([
                    'attribute_definition_id' => $definitionIds[$key], 'category_id' => $categoryId, 'sort_order' => $sort,
                    'is_required' => $required, ...($hasDefault ? ['is_default' => $required] : []),
                ]);
            }
        }
        $this->seedNames($names);
    }

    /** @param  array<string, array<string, string>>  $names  slug => locale => name */
    private function seedNames(array $names): void
    {
        if (! Schema::hasTable('category_translations')) {
            return;
        }
        $now = now();
        foreach ($names as $slug => $localized) {
            $categoryId = DB::table('categories')->where('slug', $slug)->value('id');
            foreach ($categoryId === null ? [] : $localized as $locale => $name) {
                if (! DB::table('category_translations')->where('category_id', $categoryId)->where('locale', $locale)->exists()) {
                    DB::table('category_translations')->insert(['category_id' => $categoryId, 'locale' => $locale, 'name' => $name,
                        'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }
}
