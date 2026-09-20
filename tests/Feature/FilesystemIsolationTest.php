<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FilesystemIsolationTest extends TestCase
{
    public function test_default_local_disks_are_isolated_even_without_storage_fake(): void
    {
        foreach (['public', 'local'] as $disk) {
            $root = str_replace('\\', '/', Storage::disk($disk)->path(''));
            $this->assertStringContainsString('/framework/testing/application-disks/', $root);
            $this->assertStringNotContainsString('/storage/app/', $root);
        }
    }
}
