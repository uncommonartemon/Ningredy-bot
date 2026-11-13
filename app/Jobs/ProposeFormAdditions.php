<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Catalog\FormProposals;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * After a product is formed (decision of 2026-11-13): what it has beyond its category's form
 * becomes proposals, sent to the chat the card came from; without a chat they wait on the
 * category's page in the admin.
 */
class ProposeFormAdditions implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** @param  list<array{definition_id: int, value: string, unit: ?string, variant_id: ?int}>  $held */
    public function __construct(public int $productId, public ?string $chatId = null, public array $held = [])
    {
        $this->onQueue('default');
    }

    public function handle(FormProposals $proposals, TelegramClient $telegram): void
    {
        $product = Product::query()->find($this->productId);
        if ($product === null || $proposals->propose($product, $this->held) === [] || blank($this->chatId)) {
            return;
        }
        $message = $proposals->message($product->id);
        if ($message !== null) {
            try {
                $telegram->sendMessage((string) $this->chatId, $message['text'], $message['keyboard']);
            } catch (Throwable $exception) {
                // The proposals stay open on the category's page in the admin.
                report($exception);
            }
        }
    }
}
