<?php

namespace Tests\Feature;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProgressReporter;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TelegramProgressReporterTest extends TestCase
{
    public function test_heartbeat_runs_the_callback_and_returns_its_result(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]])]);
        $progress = new TelegramProgressReporter(app(TelegramClient::class), '123');
        $progress->step('1/1 · test step', 60);

        $result = $progress->heartbeat('test label', 60, fn (): string => 'callback-result');

        $this->assertSame('callback-result', $result);
    }

    public function test_heartbeat_works_before_any_message_has_been_sent_yet(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $progress = new TelegramProgressReporter(app(TelegramClient::class), '123');

        $result = $progress->heartbeat('test label', 60, fn (): int => 42);

        $this->assertSame(42, $result);
    }

    public function test_heartbeat_lets_the_callbacks_exception_propagate(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]])]);
        $progress = new TelegramProgressReporter(app(TelegramClient::class), '123');
        $progress->step('1/1 · test step', 60);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $progress->heartbeat('test label', 60, function (): void {
            throw new RuntimeException('boom');
        });
    }

    public function test_with_cancel_button_attaches_the_button_to_the_step_message(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]])]);
        $progress = new TelegramProgressReporter(app(TelegramClient::class), '123');

        $progress->withCancelButton(42);
        $progress->step('1/1 · test step', 60);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && data_get($request['reply_markup'] ?? [], 'inline_keyboard.0.0.callback_data') === 'search:cancel:42');
    }

    public function test_the_cancel_button_is_cleared_once_heartbeat_returns(): void
    {
        // The button only makes sense for the wait it was attached to -
        // once that call is over, a later render() (e.g. the next step())
        // must not keep showing a button whose click would do nothing.
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]])]);
        $progress = new TelegramProgressReporter(app(TelegramClient::class), '123');
        $progress->withCancelButton(42);
        $progress->step('1/1 · test step', 60);

        $progress->heartbeat('test label', 60, fn (): string => 'done');
        // info()/warning() are throttled (MIN_EDIT_INTERVAL) and could be
        // skipped entirely if this runs faster than that window - done()
        // always forces a render, which is what actually needs checking.
        $progress->done('after the wait');

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText')
            && str_contains((string) ($request['text'] ?? ''), 'after the wait')
            && data_get($request['reply_markup'] ?? [], 'inline_keyboard') === []);
    }

    public function test_it_reports_the_created_message_id_for_a_later_retry(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]])]);
        $createdMessageId = null;
        $progress = new TelegramProgressReporter(
            app(TelegramClient::class),
            '123',
            onMessageCreated: function (int $messageId) use (&$createdMessageId): void {
                $createdMessageId = $messageId;
            },
        );

        $progress->step('1/1 · test step', 60);

        $this->assertSame(555, $createdMessageId);
    }

    public function test_a_retry_edits_the_existing_progress_message_instead_of_sending_another_one(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]])]);
        $progress = new TelegramProgressReporter(
            app(TelegramClient::class),
            '123',
            existingMessageId: 555,
        );

        $progress->step('1/1 · retry', 60);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText')
            && (int) ($request['message_id'] ?? 0) === 555
            && str_contains((string) ($request['text'] ?? ''), 'retry'));
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_a_log_that_outgrows_the_message_limit_seals_it_and_continues_in_a_new_message(): void
    {
        // Previously this silently truncated to only the newest lines, with
        // no way to scroll back to whatever pushed the message over 4096
        // chars. It must instead seal the first message (keeping the oldest
        // lines intact and visible) and roll the rest into a second message.
        config(['services.telegram.bot_token' => 'test-token']);
        $nextMessageId = 111;
        Http::fake(function () use (&$nextMessageId) {
            return Http::response(['ok' => true, 'result' => ['message_id' => $nextMessageId++]]);
        });
        $createdMessageIds = [];
        $progress = new TelegramProgressReporter(
            app(TelegramClient::class),
            '123',
            onMessageCreated: function (int $messageId) use (&$createdMessageIds): void {
                $createdMessageIds[] = $messageId;
            },
        );
        $progress->step('1/1 · test step', 60);

        $firstLine = 'first line marking the start of the log '.str_repeat('a', 3000);
        $progress->info($firstLine);

        for ($i = 0; $i < 20; $i++) {
            $progress->info('filler line '.$i.' '.str_repeat('b', 100));
        }

        // done() always forces a render, so it reliably flushes the final
        // in-memory state regardless of the per-message edit throttle that
        // may have silently skipped some of the intermediate info() calls.
        $progress->done('all lines processed');

        // Exactly one rollover is expected for this input size: the mega
        // first line plus a handful of filler lines fills the first message,
        // and every filler line after that comfortably fits in the second.
        $this->assertCount(2, $createdMessageIds, 'expected exactly one rollover to a second message');
        [$sealedMessageId, $continuationMessageId] = $createdMessageIds;

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText')
            && (int) ($request['message_id'] ?? 0) === $sealedMessageId
            && str_contains((string) ($request['text'] ?? ''), $firstLine)
            && str_contains((string) ($request['text'] ?? ''), 'продолжение в следующем сообщении'));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText')
            && (int) ($request['message_id'] ?? 0) === $continuationMessageId
            && str_contains((string) ($request['text'] ?? ''), 'filler line 19')
            && str_contains((string) ($request['text'] ?? ''), 'all lines processed')
            && ! str_contains((string) ($request['text'] ?? ''), $firstLine));
    }
}
