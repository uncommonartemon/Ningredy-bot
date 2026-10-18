<?php

namespace Tests\Unit;

use App\Services\Admin\DatabaseValueRedactor;
use Tests\TestCase;

class DatabaseValueRedactorTest extends TestCase
{
    public function test_header_style_and_compact_secret_names_are_hidden(): void
    {
        $redactor = new DatabaseValueRedactor;
        foreach (['api-key', 'x-api-key', 'X-Api-Key', 'apiKey', 'apikey', 'x_goog_api_key', 'Authorization', 'bot-token'] as $name) {
            $this->assertTrue($redactor->secretKey($name), $name);
        }
        foreach (['keyboard_layout', 'monkey', 'key_count', 'tokenizer_version_label'] as $name) {
            $this->assertFalse($redactor->secretKey($name), $name);
        }
    }

    public function test_nested_header_secret_never_reaches_the_rendered_value(): void
    {
        $value = (new DatabaseValueRedactor)->value(['request' => ['headers' => ['x-api-key' => 'live-secret-value-123', 'accept' => 'json']]]);

        $this->assertSame(DatabaseValueRedactor::HIDDEN, $value['request']['headers']['x-api-key']);
        $this->assertSame('json', $value['request']['headers']['accept']);
    }
}
