<?php

namespace Tests\Unit;

use App\Services\Photos\ImageRenditionProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImageRenditionProbeTest extends TestCase
{
    /** Addresses dropped as too small over 45 live searches, and the larger rendition each names. */
    #[DataProvider('thumbnails')]
    public function test_a_thumbnail_address_names_where_its_larger_rendition_is(string $thumbnail, string $expectedFirst, array $alsoExpected = []): void
    {
        $guesses = ImageRenditionProbe::largerRenditions($thumbnail);

        $this->assertSame($expectedFirst, $guesses[0] ?? null, implode("\n", $guesses));
        foreach ($alsoExpected as $guess) {
            $this->assertContains($guess, $guesses);
        }
        $this->assertNotContains($thumbnail, $guesses);
        $this->assertLessThanOrEqual(4, count($guesses));
    }

    public static function thumbnails(): array
    {
        return [
            'shopify size suffix (#161 kwancorp)' => ['https://www.kwancorp.com/cdn/shop/files/X1404VA-EB2113WSM_300x.png?v=1792787868',
                'https://www.kwancorp.com/cdn/shop/files/X1404VA-EB2113WSM.png?v=1792787868',
                ['https://www.kwancorp.com/cdn/shop/files/X1404VA-EB2113WSM_1600x.png?v=1792787868']],
            'shopify retina suffix' => ['https://www.kwancorp.com/cdn/shop/files/NextPhase_250x@2x.png?v=1759889014',
                'https://www.kwancorp.com/cdn/shop/files/NextPhase.png?v=1759889014'],
            'wordpress size and width query (#161 dwinstore)' => ['https://dwinstore.com/wp-content/uploads/2024/02/acer-aspire-lite-500x500-1.webp?w=640',
                'https://dwinstore.com/wp-content/uploads/2024/02/acer-aspire-lite-500x500-1.webp',
                ['https://dwinstore.com/wp-content/uploads/2024/02/acer-aspire-lite-1.webp?w=640']],
            'bigcommerce size directory (#87)' => ['https://cdn11.bigcommerce.com/s-c3nic6rosw/images/stencil/400x400/products/5383/24354/131427007__58248.1691193113.jpg?c=1',
                'https://cdn11.bigcommerce.com/s-c3nic6rosw/images/stencil/1600x1600/products/5383/24354/131427007__58248.1691193113.jpg?c=1'],
            'uploadcare resize (#87 acer press)' => ['https://cdn.assets.prezly.com/1398820a-767a-44ee-974c-33b2c462bc3e/-/preview/1500x1500/-/resize/400/Nitro_14.png',
                'https://cdn.assets.prezly.com/1398820a-767a-44ee-974c-33b2c462bc3e/-/preview/1500x1500/Nitro_14.png'],
            'next.js image proxy (#87 pcnation)' => ['https://www.pcnation.com/_next/image?url=https%3A%2F%2Fwww.pcnation.com%2Fapi%2Fmedia%2Ffile%2Flaptops-500x500.png&w=256&q=75',
                'https://www.pcnation.com/api/media/file/laptops-500x500.png'],
            'height query (laptopoutlet)' => ['https://static-media.laptopoutlet.co.uk/catalog/product/d/7/d795c76893cd67570d4197987ed912fc.jpg?height=650',
                'https://static-media.laptopoutlet.co.uk/catalog/product/d/7/d795c76893cd67570d4197987ed912fc.jpg'],
            'named small rendition (kns)' => ['https://www.kns.ru/linkpics/hp-envy-x360-14-fc0006ci_kod_895687-small.jpg',
                'https://www.kns.ru/linkpics/hp-envy-x360-14-fc0006ci_kod_895687.jpg'],
            'size directory with prefix (itarena)' => ['https://www.itarena.ro/images/products/2024-07-04/300x300/443940_jpg.webp',
                'https://www.itarena.ro/images/products/2024-07-04/1600x1600/443940_jpg.webp'],
            'width directory without an extension (#181 asus)' => ['https://dlcdnwebimgs.asus.com/gain/419bcbb5-b469-4805-b50a-56f41dab768c/w80/fwebp',
                'https://dlcdnwebimgs.asus.com/gain/419bcbb5-b469-4805-b50a-56f41dab768c/w1600/fwebp',
                ['https://dlcdnwebimgs.asus.com/gain/419bcbb5-b469-4805-b50a-56f41dab768c/fwebp']],
        ];
    }

    public function test_an_address_that_names_no_rendition_gives_no_guesses(): void
    {
        $this->assertSame([], ImageRenditionProbe::largerRenditions('https://images.icecat.biz/img/gallery/86675d0ed90456c979edcc2f243ae6f3e155597d.jpg'));
        $this->assertSame([], ImageRenditionProbe::largerRenditions('not a url'));
    }
}
