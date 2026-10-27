<?php

namespace Database\Seeders;

use App\Models\BrandCatalog;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Starting knowledge of where brands keep their own catalogs. Data, not logic:
 * nothing branches on a brand; the research agent reads these rows as hints and
 * the operator edits them in Filament ("Каталоги брендов"). Only web-search
 * hints are seeded - a page or API template is added once someone has checked
 * that it opens a product by its code. An existing row is never overwritten.
 */
class BrandCatalogSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array CATALOGS = [
        ['brand' => 'Lenovo', 'catalog_url' => 'https://psref.lenovo.com', 'search_hint' => 'site:psref.lenovo.com {code}',
            'instructions' => 'PSREF — справочник Lenovo: модель по MTM (например 83JJ000ASB) с полными характеристиками и фото платформы. Фото платформы общие для конфигураций: цвет и порты сверять.'],
        ['brand' => 'HP', 'catalog_url' => 'https://support.hp.com', 'search_hint' => '{code} site:support.hp.com specifications',
            'instructions' => 'Характеристики HP — по номеру продукта (например 8F2J3UA) на support.hp.com; QuickSpecs семейства — PDF с фото.'],
        ['brand' => 'Dell', 'catalog_url' => 'https://www.dell.com/support', 'search_hint' => 'site:dell.com {model} setup and specifications',
            'instructions' => 'У Dell характеристики модели — в документе «Setup and Specifications»; конфигурации продаются на dell.com.'],
        ['brand' => 'ASUS', 'catalog_url' => 'https://www.asus.com', 'search_hint' => 'site:asus.com {model} techspec',
            'instructions' => 'Страница модели ASUS: вкладка Tech Specs; региональные SKU (…-PV530W) указаны не всегда.'],
        ['brand' => 'Acer', 'catalog_url' => 'https://www.acer.com', 'search_hint' => 'site:acer.com {code}',
            'instructions' => 'Acer показывает номер изделия NX.xxxxx.xxx на странице конфигурации.'],
        ['brand' => 'MSI', 'catalog_url' => 'https://www.msi.com', 'search_hint' => 'site:msi.com {model} specification',
            'instructions' => 'Страница модели MSI: раздел Specification перечисляет SKU модели.'],
        ['brand' => 'Samsung', 'catalog_url' => 'https://www.samsung.com', 'search_hint' => 'site:samsung.com {code}', 'instructions' => null],
        ['brand' => 'Razer', 'catalog_url' => 'https://www.razer.com', 'search_hint' => 'site:razer.com {code}', 'instructions' => null],
        ['brand' => 'Microsoft', 'aliases' => ['Surface'], 'catalog_url' => 'https://www.microsoft.com/surface', 'search_hint' => 'site:microsoft.com {model} tech specs', 'instructions' => null],
        ['brand' => 'Apple', 'catalog_url' => 'https://support.apple.com/specs', 'search_hint' => 'site:support.apple.com {model} tech specs', 'instructions' => null],
        ['brand' => 'Kingston', 'catalog_url' => 'https://www.kingston.com', 'search_hint' => 'site:kingston.com {code}', 'instructions' => null],
        ['brand' => 'Corsair', 'catalog_url' => 'https://www.corsair.com', 'search_hint' => 'site:corsair.com {code}', 'instructions' => null],
        ['brand' => 'Crucial', 'catalog_url' => 'https://www.crucial.com', 'search_hint' => 'site:crucial.com {code}', 'instructions' => null],
        ['brand' => 'Western Digital', 'aliases' => ['WD', 'WD_BLACK'], 'catalog_url' => 'https://www.westerndigital.com', 'search_hint' => 'site:westerndigital.com {code}', 'instructions' => null],
    ];

    public function run(): void
    {
        foreach (self::CATALOGS as $catalog) {
            BrandCatalog::query()->firstOrCreate(['brand' => $catalog['brand']], [...$catalog, 'enabled' => true]);
        }
    }
}
