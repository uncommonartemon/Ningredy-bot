<?php

namespace Tests\Feature;

use App\Ai\Tools\GetProductSearchIntent;
use App\Models\Category;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class GetProductSearchIntentToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_both_the_telegram_request_text_and_the_category_hint(): void
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(1000, 9000),
            'telegram_user_id' => '111',
            'chat_id' => '222',
            'message_id' => 1,
            'payload' => [],
            'text' => 'найди зелёный ноутбук асус, не серебристый',
            'status' => 'processing',
        ]);
        Category::query()->create([
            'name' => 'Laptops',
            'slug' => 'zzz-scratch-laptops-intent',
            'product_search_hint' => 'не используй фото товара в коробке',
        ]);

        $result = json_decode(
            (string) (new GetProductSearchIntent($update->id, 'zzz-scratch-laptops-intent'))
                ->handle(new ToolRequest),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('найди зелёный ноутбук асус, не серебристый', $result['telegram_request_text']);
        $this->assertSame('не используй фото товара в коробке', $result['category_search_hint']);
    }

    public function test_both_fields_are_null_when_nothing_is_bound(): void
    {
        $result = json_decode(
            (string) (new GetProductSearchIntent(null, null))->handle(new ToolRequest),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertNull($result['telegram_request_text']);
        $this->assertNull($result['category_search_hint']);
    }

    public function test_a_missing_category_hint_does_not_block_the_telegram_text(): void
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(1000, 9000),
            'telegram_user_id' => '111',
            'chat_id' => '222',
            'message_id' => 1,
            'payload' => [],
            'text' => 'найди зелёный ноутбук асус',
            'status' => 'processing',
        ]);

        $result = json_decode(
            (string) (new GetProductSearchIntent($update->id, 'no-such-category'))->handle(new ToolRequest),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('найди зелёный ноутбук асус', $result['telegram_request_text']);
        $this->assertNull($result['category_search_hint']);
    }
}
