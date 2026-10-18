<?php

namespace App\Jobs;

use App\Models\AiOperation;
use App\Services\Telegram\TelegramAssistantReplyDelivery;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Delivery retries never reenter paid product search or card assembly. */
class DeliverTelegramAssistantReply implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 600;

    public array $backoff = [30, 120];

    public function __construct(public int $checkpointId)
    {
        $this->onQueue('assistant');
        $connection = (string) config('queue.default');
        if (config("queue.connections.{$connection}.driver") === 'sync') {
            foreach ((array) config('queue.connections') as $name => $settings) {
                if (($settings['driver'] ?? null) === 'database') {
                    $this->onConnection($name);

                    return;
                }
            }
            throw new \LogicException('Telegram delivery retries require an asynchronous queue connection.');
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->checkpointId;
    }

    public function handle(TelegramAssistantReplyDelivery $delivery, TelegramClient $telegram): void
    {
        $checkpoint = AiOperation::find($this->checkpointId);
        if ($checkpoint && $checkpoint->action === 'deliver_assistant_reply') {
            $delivery->deliver($checkpoint, $telegram);
        }
    }
}
