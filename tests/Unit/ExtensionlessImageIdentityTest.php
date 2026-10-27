<?php

namespace Tests\Unit;

use App\Services\Photos\ImageUrls;
use App\Services\Products\ProductGalleryRecipeResultValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtensionlessImageIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const ASSET = 'https://cdn.example/is/gallery/6b056720669b42889a14019342fec822';

    public function test_extensionless_renditions_count_once_without_changing_download_urls(): void
    {
        $urls = array_map(fn ($size) => self::ASSET.'?version=0&x='.$size.'&quality=75', [256, 384, 640, 750, 1080, 1920]);
        $this->assertCount(1, array_unique(array_map(ImageUrls::imageAssetKey(...), $urls)));
        foreach ($urls as $url) {
            $this->assertSame($url, ImageUrls::normalizeCandidateUrl($url));
        }
    }

    public function test_cms_image_cache_renditions_and_their_original_are_one_frame(): void
    {
        // Live #273 jtfcorp.com: the same photo at 120x120 and 600x600 in two Magento cache
        // directories counted as two frames, and the 900x515 original sits outside the cache.
        $product = 'https://www.jtfcorp.com/media/catalog/product/';
        $keys = array_unique(array_map(ImageUrls::imageAssetKey(...), [
            $product.'cache/77bea8c14f304a0f0e40165f8c18646f/i/m/img_0_A6TP0UT_ABA_1780476523_1.jpg',
            $product.'cache/71dbad14b00e391f00d8d8b368cc891c/i/m/img_0_A6TP0UT_ABA_1780476523_1.jpg',
            $product.'i/m/img_0_A6TP0UT_ABA_1780476523_1.jpg',
        ]));

        $this->assertCount(1, $keys);
        $this->assertNotSame(ImageUrls::imageAssetKey($product.'i/m/img_0_A6TP0UT_ABA_1780476523_1.jpg'),
            ImageUrls::imageAssetKey($product.'cache/77bea8c14f304a0f0e40165f8c18646f/i/m/img_1_A6TP0UT_ABA_1780476523_1.jpg'));
    }

    public function test_shop_image_types_and_width_buckets_of_one_image_are_one_frame(): void
    {
        // Live #250 dataspot.ro (PrestaShop) and a BigCommerce shop of the series: every stock
        // size of an image and its original are one frame; another image id is another frame.
        $shop = 'https://dataspot.ro/';
        $this->assertCount(1, array_unique(array_map(ImageUrls::imageAssetKey(...), [
            $shop.'308628-large_default/laptop-razer-blade-14.jpg', $shop.'308628-home_default/laptop-razer-blade-14.jpg',
            $shop.'308628-thickbox_default/laptop-razer-blade-14.jpg', $shop.'308628/laptop-razer-blade-14.jpg',
        ])));
        $this->assertNotSame(ImageUrls::imageAssetKey($shop.'308628-large_default/laptop-razer-blade-14.jpg'),
            ImageUrls::imageAssetKey($shop.'308629-large_default/laptop-razer-blade-14.jpg'));

        $stencil = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/';
        $this->assertCount(1, array_unique(array_map(ImageUrls::imageAssetKey(...), [
            $stencil.'320w/products/430599/2828792/tablet__12345.jpg', $stencil.'original/products/430599/2828792/tablet__12345.jpg',
            $stencil.'1280x1280/products/430599/2828792/tablet__12345.jpg',
        ])));
    }

    public function test_a_url_lifted_out_of_inline_json_loses_its_escaped_slashes(): void
    {
        // Live #1: sixteen product photos arrived as https://shop.example\/getimage/... and were dropped as not public.
        $this->assertSame('https://shop.example/getimage/421x421/products/9Y6X8AT_0.jpg',
            ImageUrls::normalizeCandidateUrl('https://shop.example\/getimage\/421x421\/products\/9Y6X8AT_0.jpg'));
        $this->assertSame('https://shop.example/a/b.jpg', ImageUrls::normalizeCandidateUrl('https://shop.example/a/b.jpg'));
    }

    public function test_digest_does_not_erase_frame_selecting_query_parameters_or_host(): void
    {
        foreach (['', '.jpg'] as $extension) {
            $url = self::ASSET.$extension;
            $this->assertNotSame(ImageUrls::imageAssetKey($url.'?id=front'), ImageUrls::imageAssetKey($url.'?id=back'));
            $this->assertNotSame(ImageUrls::imageAssetKey($url.'?x=front'), ImageUrls::imageAssetKey($url.'?x=back'));
            $this->assertNotSame(ImageUrls::imageAssetKey($url.'?id=a&id=b'), ImageUrls::imageAssetKey($url.'?id=b'));
            $this->assertNotSame(ImageUrls::imageAssetKey($url.'?version=0'), ImageUrls::imageAssetKey($url.'?version=1'));
            $this->assertNotSame(ImageUrls::imageAssetKey($url), ImageUrls::imageAssetKey(str_replace('cdn.example', 'other.example', $url)));
            $this->assertSame(ImageUrls::imageAssetKey($url.'?frame=front&x=256&version=0'),
                ImageUrls::imageAssetKey($url.'?version=0&x=1600&frame=front'));
        }
        $this->assertNotSame(ImageUrls::imageAssetKey('https://cdn.example/one/12345678901234567890123456789012'),
            ImageUrls::imageAssetKey('https://cdn.example/two/12345678901234567890123456789012'));
    }

    public function test_renditions_cannot_claim_a_complete_four_frame_gallery(): void
    {
        $urls = [];
        foreach (['6b056720669b42889a14019342fec822', '64e60ab548694d5db567ae9d30b27855'] as $hash) {
            foreach ([750, 1080, 1920] as $size) {
                $urls[] = 'https://cdn.example/is/gallery/'.$hash.'?version=0&x='.$size.'&quality=75';
            }
        }
        $recipe = ['gallery_present' => true, 'content_confirmed_product' => true];
        $result = ['images' => $urls, 'diagnostics' => ['observed_gallery_count' => 4]];
        $validation = app(ProductGalleryRecipeResultValidator::class)->validate($recipe, $result, countedOnThisPage: false);
        $this->assertFalse($validation['passed']);
        $this->assertSame(2, $validation['extracted']);
        $this->assertSame(4, $validation['expected']);
        $result['images'][] = 'https://cdn.example/is/gallery/57cc08af92cc456190eaf2ab22b776aa?x=1200';
        $result['images'][] = 'https://cdn.example/is/gallery/0f96949ccc3d417bade64177734afd3d?x=1200';
        $this->assertTrue(app(ProductGalleryRecipeResultValidator::class)->validate($recipe, $result, countedOnThisPage: false)['passed']);
    }
}
