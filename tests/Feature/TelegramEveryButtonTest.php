<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Crawls the Telegram interface the way the operator sees it: starts from a
 * real draft review card and a catalog product card, presses every inline
 * button, collects the buttons of every message or menu the bot answers with,
 * and presses those too. Every press must answer 200 and log no error.
 */
class TelegramEveryButtonTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '98765';

    private int $recorded = 0;

    /** @var list<string> */
    private array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
        Queue::fake();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345']]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 80]]),
            '*' => Http::response('', 200),
        ]);
        Event::listen(MessageLogged::class, function (MessageLogged $log): void {
            if (in_array($log->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                $this->errors[] = mb_substr($log->message, 0, 240);
            }
        });
    }

    public function test_every_telegram_button_answers_without_an_error(): void
    {
        $draft = $this->pendingDraft();
        $product = $this->catalogProduct();
        app(DraftTelegramPresenter::class)->sendReview(app(TelegramClient::class), self::CHAT, $draft);
        // States with their own buttons: photos kept but the card unreconciled ("accept as is"),
        // and a search paused by its time limit ("continue search").
        $unreconciled = $this->pendingDraft(40002, ['specifications_reconciled_source_url' => null,
            'gallery_search_stop_reason' => 'specifications_unreconciled']);
        app(DraftTelegramPresenter::class)->sendReview(app(TelegramClient::class), self::CHAT, $unreconciled);
        $paused = $this->pendingDraft(40003, ['gallery_status' => 'missing', 'gallery_confirmed_sufficient' => false,
            'gallery_search_stop_reason' => 'time_budget'], photos: 0);
        app(DraftTelegramPresenter::class)->sendReview(app(TelegramClient::class), self::CHAT, $paused);
        $queue = $this->newButtons();
        // Catalog product controls come from the catalog card, not from a draft.
        foreach (["product:variants:{$product->id}:0", "product:tgedit:{$product->id}", "product:remove:{$product->id}", 'product:create'] as $data) {
            $queue[$data] = true;
        }

        $report = [];
        $pressed = [];
        while ($queue !== [] && count($pressed) < 150) {
            // Everything reversible first; approving, rejecting and deleting at the very end.
            $next = collect(array_keys($queue))->first(fn (string $data): bool => ! $this->destructive($data))
                ?? array_key_first($queue);
            unset($queue[$next]);
            $pressed[$next] = true;
            $this->errors = [];
            $response = $this->postJson('/api/telegram/webhook', ['update_id' => 500000 + count($pressed), 'callback_query' => [
                'id' => 'crawl-'.count($pressed), 'from' => ['id' => 12345],
                'data' => $next, 'message' => ['message_id' => 80, 'chat' => ['id' => (int) self::CHAT]],
            ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']);
            $report[] = [$next, $response->status() === 200 && $this->errors === [] ? 'ok' : 'ERROR '.$response->status().' '.implode(' | ', $this->errors)];
            foreach ($this->newButtons() as $data => $ignored) {
                if (! isset($pressed[$data])) {
                    $queue[$data] = true;
                }
            }
        }

        @file_put_contents(storage_path('logs/telegram-every-button.txt'), implode(PHP_EOL, array_map(fn (array $row): string => str_pad($row[0], 40).' '.$row[1], $report)).PHP_EOL);
        $failed = array_values(array_filter($report, fn (array $row): bool => str_starts_with($row[1], 'ERROR')));
        $this->assertSame([], $failed, json_encode($failed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->assertGreaterThan(15, count($report), 'The crawl should reach the draft and product menus.');
    }

    /** Callback data of every inline button in requests sent since the last call. @return array<string, true> */
    private function newButtons(): array
    {
        $requests = Http::recorded()->slice($this->recorded)->values();
        $this->recorded += $requests->count();
        $buttons = [];
        foreach ($requests as [$request]) {
            $markup = $request['reply_markup'] ?? null;
            $markup = is_string($markup) ? json_decode($markup, true) : $markup;
            foreach ((array) data_get($markup, 'inline_keyboard', []) as $row) {
                foreach ((array) $row as $button) {
                    if (is_string($button['callback_data'] ?? null)) {
                        $buttons[$button['callback_data']] = true;
                    }
                }
            }
        }

        return $buttons;
    }

    private function destructive(string $data): bool
    {
        return (bool) preg_match('/^(draft:(add|approve|asisok|reject|delete)|product:(remove|delete:confirm)|draft:source-block-confirm)/', $data)
            || str_contains($data, 'delete-photo');
    }

    private function pendingDraft(int $updateId = 40001, array $overrides = [], int $photos = 3): ProductDraft
    {
        $update = TelegramUpdate::query()->create(['update_id' => $updateId, 'telegram_user_id' => '12345', 'chat_id' => self::CHAT,
            'message_id' => 79, 'text' => 'Find exact product', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'gpt-6-luna',
            'status' => 'completed', 'prompt' => 'Find exact product', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345', 'title' => 'MSI Aegis Exact', 'brand' => 'MSI', 'model' => 'Aegis Exact',
            'color' => 'Black', 'condition' => 'new', 'product_type' => 'desktop', 'category' => 'desktops',
            'description' => 'Exact product description.', 'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB']],
            'sources' => [['title' => 'Shop', 'url' => 'https://shop.example/dp/EXACT', 'type' => 'retailer']],
            'primary_source_url' => 'https://shop.example/dp/EXACT', 'specifications_reconciled_source_url' => 'https://shop.example/dp/EXACT',
            'image_urls' => [], 'images_staged_at' => now(), 'gallery_status' => 'complete', 'gallery_confirmed_sufficient' => true,
            'confidence' => 0.95, 'status' => 'pending_review', ...$overrides]);
        foreach ($photos > 0 ? range(1, $photos) : [] as $position) {
            $path = "drafts/{$draft->id}/photo-{$position}.webp";
            Storage::disk('public')->put($path, "photo-{$position}");
            $draft->media()->create(['disk' => 'public', 'path' => $path, 'source_url' => "https://images.example/photo-{$position}.jpg",
                'role' => $position === 1 ? 'primary' : 'secondary', 'mime_type' => 'image/webp', 'checksum' => hash('sha256', "photo-{$position}"),
                'verification_status' => 'verified', 'sort_order' => $position - 1, 'is_primary' => $position === 1]);
        }

        return $draft->fresh();
    }

    private function catalogProduct(): Product
    {
        $category = Category::query()->where('slug', 'laptops')->first() ?? Category::create(['name' => 'Laptops', 'product_type_affinity' => 'laptop']);
        $product = Product::create(['category_id' => $category->id, 'title' => 'Catalog laptop', 'product_type' => 'laptop',
            'status' => 'published', 'is_active' => true]);
        $product->variants()->create(['condition' => 'new', 'currency' => 'CZK', 'price' => 100, 'stock_status' => 'in_stock',
            'quantity' => 1, 'is_default' => true, 'is_active' => true]);

        return $product;
    }
}
