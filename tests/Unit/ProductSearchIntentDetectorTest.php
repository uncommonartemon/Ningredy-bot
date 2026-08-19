<?php

namespace Tests\Unit;

use App\Services\Products\ProductSearchIntentDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductSearchIntentDetectorTest extends TestCase
{
    #[DataProvider('manualProductQueries')]
    public function test_manual_product_queries_are_routed_to_tools(string $query): void
    {
        $this->assertTrue(app(ProductSearchIntentDetector::class)->isStandaloneProductQuery($query));
    }

    public function test_photo_management_and_questions_are_not_misrouted_as_new_products(): void
    {
        $detector = app(ProductSearchIntentDetector::class);

        $this->assertFalse($detector->isStandaloneProductQuery('замени второе фото Acer Nitro V16 RTX 5060'));
        $this->assertFalse($detector->isStandaloneProductQuery('что такое SKU?'));
        $this->assertFalse($detector->isStandaloneProductQuery('как улучшить поиск?'));
    }

    /** @return array<string, array{string}> */
    public static function manualProductQueries(): array
    {
        return [
            'acer nitro' => ['Acer nitro V16 AI, R7 260, RTX 5060'],
            'msi vector' => ['MSI Vector 17 HX AI A2XWJG-009FR'],
            'asus vivobook' => ['ASUS Vivobook 16 X1605VA'],
            'msi generic' => ['Herní notebook Msi 17 RTX 5070, i7 14gen'],
            'intel 6700' => ['Intel core i7 6700'],
            'intel 10700' => ['Intel core i7 10700'],
            'intel 8700' => ['Intel core i7 8700'],
            'ecc ram' => ['Ddr4 256gb 3200mhz ECC RDIMM'],
            'asus rog' => ['Asus ROG z RTX 3080, R7 5800H'],
            'acer v15' => ['Acer nitro v15 RTX 5060 8gb 16gb ram 1tb SSD'],
            'acer v16' => ['Acer nitro v16 ai Ryzen 9 ai 365 RTX 5070 32gb ram 1tb SSD'],
            'dell 14' => ['Dell pro max 14 Premium'],
            'core ultra' => ['Intel core ultra 5 235'],
            'adata ram' => ['Adata ddr5 32gb (2x16) 6000mhz SO-DIMM ram'],
            'ultra 9' => ['Core ultra 9 285K LGA 1851'],
            'sodimm ddr4' => ['SO-DIMM DDR4 64gb (2x32gb) 2666mhz ram'],
            'dell 18' => ['Dell pro max 18 plus MB18250'],
            'lenovo loq' => ['Lenovo LOQ AMD Ryzen 5 7640HS'],
            'sodimm ddr5' => ['64gb ddr5 6400mhz SO-DIMM'],
            'samsung qvo' => ['Samsung SSD 870 QVO 4TB'],
        ];
    }
}
