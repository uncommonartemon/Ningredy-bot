<?php

namespace Tests\Feature;

use App\Services\Products\ProductImageEncoder;
use App\Services\Support\WorkerMemory;
use Tests\TestCase;

/**
 * The colleague's #343 (2026-11-13): a search worker died at 512 MB while decoding enlarged
 * pictures. Every queued job now runs with the image limit, and a picture that does not fit in
 * what the worker has left is skipped instead of ending the process.
 */
class WorkerMemoryTest extends TestCase
{
    private string $before;

    protected function setUp(): void
    {
        parent::setUp();
        $this->before = (string) ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->before);
        parent::tearDown();
    }

    public function test_the_limit_is_raised_never_lowered_and_an_unlimited_one_is_left_alone(): void
    {
        ini_set('memory_limit', '2048M');
        WorkerMemory::raise('1024M');
        $this->assertSame('2048M', ini_get('memory_limit'));

        ini_set('memory_limit', '-1');
        WorkerMemory::raise('1024M');
        $this->assertSame('-1', ini_get('memory_limit'));
        $this->assertSame(PHP_INT_MAX, WorkerMemory::free());
    }

    public function test_every_queued_job_runs_with_the_image_limit(): void
    {
        ini_set('memory_limit', '512M');
        config(['product-images.worker_memory_limit' => '1024M']);

        dispatch(function (): void {});

        $this->assertSame('1024M', ini_get('memory_limit'));
    }

    public function test_a_picture_that_does_not_fit_in_the_free_memory_is_not_decoded(): void
    {
        $limit = intdiv(memory_get_usage(true), 1048576) + 200;
        ini_set('memory_limit', $limit.'M');
        $encoder = app(ProductImageEncoder::class);

        // 4000x4000 needs about 80 MB plus the 64 MB reserve: it fits in 200 MB; 6000x6000 does not.
        $this->assertTrue($encoder->fitsInMemory(4000, 4000));
        $this->assertFalse($encoder->fitsInMemory(6000, 6000));
        $this->assertTrue($encoder->isSafeToDecode(6000, 6000));
    }
}
