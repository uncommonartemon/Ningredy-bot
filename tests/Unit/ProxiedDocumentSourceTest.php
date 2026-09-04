<?php

namespace Tests\Unit;

use App\Services\Products\ProductImageCandidateDiscovery;
use ReflectionMethod;
use Tests\TestCase;

class ProxiedDocumentSourceTest extends TestCase
{
    public function test_a_pdf_hidden_inside_an_asset_proxy_is_recognised_before_it_is_fetched(): void
    {
        // Live case (2026-09-04): this URL carries no extension and no clue in
        // its path, so it was fetched as a product page and only then rejected
        // for being application/pdf. Its base64 segment decodes to
        // icecat.biz/rest/product-pdf - readable for free, before any request.
        $this->assertFalse($this->looksLikeHtml(
            'https://prod.isg.bruneau.media/asset/aHR0cHM6Ly9pY2VjYXQuYml6L3Jlc3QvcHJvZHVjdC1wZGY'
                .'/cHJvZHVjdElkPTEzMDgwNjQ3NiZsYW5nPW5s/?quality=85',
        ));
    }

    public function test_a_proxy_carrying_an_ordinary_product_page_still_passes(): void
    {
        // The rule reads what the proxy carries; it does not distrust proxies.
        $this->assertTrue($this->looksLikeHtml(
            'https://cdn.example.com/asset/aHR0cHM6Ly9zaG9wLmV4YW1wbGUuY29tL3Byb2R1Y3QvbGFwdG9w/',
        ));
    }

    public function test_a_product_whose_own_name_contains_pdf_is_not_mistaken_for_a_document(): void
    {
        // The looser word-boundary match applies only to a decoded proxy
        // target, never to the visible URL, where "pdf" can be part of a name.
        $this->assertTrue($this->looksLikeHtml('https://shop.example.com/products/hp-pdf-viewer-laptop-15'));
    }

    public function test_a_plain_pdf_link_is_still_rejected_by_its_extension(): void
    {
        $this->assertFalse($this->looksLikeHtml(
            'https://www.acerid.com/content/uploads/2026/04/Acer-Predator-Catalogue.pdf',
        ));
    }

    private function looksLikeHtml(string $url): bool
    {
        $discovery = app(ProductImageCandidateDiscovery::class);

        return (new ReflectionMethod($discovery, 'isLikelyHtmlImageSource'))->invoke($discovery, $url);
    }
}
