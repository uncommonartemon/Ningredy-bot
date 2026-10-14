<?php

namespace Tests\Unit;

use App\Services\ProductSearch\Discovery\DiscoveryRecoveryState;
use App\Services\ProductSearch\Discovery\DiscoveryResponseParser;
use App\Services\ProductSearch\Discovery\InvalidDiscoveryResponse;
use Tests\TestCase;

class DiscoveryResponseParserTest extends TestCase
{
    public function test_prose_without_result_fields_is_not_an_empty_search_result(): void
    {
        $this->expectException(InvalidDiscoveryResponse::class);
        (new DiscoveryResponseParser)->parse(['summary' => 'Still searching.']);
    }

    public function test_a_null_result_field_requests_repair_instead_of_silently_becoming_empty(): void
    {
        try {
            (new DiscoveryResponseParser)->parse(['sources' => null]);
            $this->fail('A null result is not an explicit empty list.');
        } catch (InvalidDiscoveryResponse $error) {
            $this->assertSame('invalid_result_field:sources', $error->reason);
        }
    }

    public function test_legacy_single_result_field_is_preserved_and_other_fields_default_to_lists(): void
    {
        $result = (new DiscoveryResponseParser)->parse([
            'page_urls' => [' https://shop.example/product?id=front ', 'https://shop.example/product?id=back'],
        ]);
        $this->assertSame(['https://shop.example/product?id=front', 'https://shop.example/product?id=back'], $result['page_urls']);
        $this->assertSame([], $result['sources']);
        $this->assertSame([], $result['image_urls']);
    }

    public function test_explicit_empty_lists_remain_a_valid_no_results_answer(): void
    {
        $empty = ['sources' => [], 'image_urls' => [], 'page_urls' => []];
        $this->assertEquals($empty, (new DiscoveryResponseParser)->parse($empty));
    }

    public function test_actual_queries_are_preserved_for_the_next_search(): void
    {
        $result = (new DiscoveryResponseParser)->parse([
            'sources' => [], 'image_urls' => [], 'page_urls' => [],
            'queries_tried' => ['ANV15-51-52U3 Mexico tienda'],
        ]);
        $this->assertSame(['ANV15-51-52U3 Mexico tienda'], $result['queries_tried']);
    }

    public function test_alternating_invalid_responses_cannot_restart_the_same_repair_forever(): void
    {
        $state = new DiscoveryRecoveryState;
        $state->begin('draft:run');
        $missing = new InvalidDiscoveryResponse('missing_result_fields', 'Return the final JSON.');
        $invalid = new InvalidDiscoveryResponse('invalid_result_field:sources', 'Return an array.');
        $this->assertTrue($state->failed($missing));
        $this->assertTrue($state->failed($invalid));
        $this->assertFalse($state->failed($missing));
        $this->assertTrue($state->stopped());
        $state->begin('draft:next-run');
        $this->assertFalse($state->stopped());
        $this->assertSame('', $state->promptFeedback());
    }
}
