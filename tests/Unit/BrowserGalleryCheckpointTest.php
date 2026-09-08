<?php

namespace Tests\Unit;

use App\Services\Products\BrowserGalleryCheckpoint;
use PHPUnit\Framework\TestCase;

class BrowserGalleryCheckpointTest extends TestCase
{
    public function test_timeout_recovers_observations_but_never_validates_photos(): void
    {
        $directory = sys_get_temp_dir().'/gallery-checkpoint-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            file_put_contents($directory.'/checkpoint.json', json_encode([
                'images' => ['https://example.com/raw.jpg'],
                'scout' => ['fragments' => ['gallery']],
                'action_trace' => [['clicked' => true]],
                'diagnostics' => ['browser_stage' => 'screenshot'],
            ]));
            $result = BrowserGalleryCheckpoint::interrupted($directory, 'timeout');
            $this->assertSame(['fragments' => ['gallery']], $result['scout']);
            $this->assertSame([], $result['images']);
            $this->assertSame('screenshot', $result['diagnostics']['browser_stage']);
            $this->assertTrue($result['diagnostics']['stopped_early']);
            $this->assertSame('browser_timeout', $result['failure_kind']);
            file_put_contents($directory.'/checkpoint.json', '{broken');
            $this->assertSame([], BrowserGalleryCheckpoint::interrupted($directory, 'timeout')['scout']);
        } finally {
            unlink($directory.'/checkpoint.json');
            rmdir($directory);
        }
    }
}
