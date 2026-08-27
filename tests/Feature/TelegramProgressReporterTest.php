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

    public function test_log_lines_are_grouped_under_a_domain_header_with_the_full_url_shown_once(): void
    {
        // Real user request (2026-08-26): a multi-source search interleaves
        // narration for several retailer domains in one flat stream, making
        // it hard to tell which line belongs to which site. Group under a
        // "домен X:" header the first time a domain is mentioned, and print
        // the full product-page URL exactly once per domain instead of
        // repeating it (or never showing it in full) on every line.
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]])]);
        $progress = new TelegramProgressReporter(app(TelegramClient::class), '123');
        $progress->step('1/1 · test step', 60);

        $progress->info('Playwright: применяю AI-рецепт для delkom.pl.');
        $progress->info('Playwright получил фото: 16.');
        $progress->info('Для www.catsrl.it ещё нет AI-рецепта; запускаю первичное обучение.');
        $progress->info('Проверяю источник: https://www.catsrl.it/shop/legion-9-18iax10-25892?category=1351');
        $progress->info('AI-тренер: gpt-5.4 строит безопасный JSON-рецепт.');
        $progress->done('поиск завершён');

        Http::assertSent(function ($request): bool {
            $text = (string) ($request['text'] ?? '');

            if (! str_ends_with($request->url(), '/editMessageText') || ! str_contains($text, 'поиск завершён')) {
                return false;
            }

            $this->assertSame(1, substr_count($text, 'домен delkom.pl:'));
            $this->assertSame(1, substr_count($text, 'домен catsrl.it:'));
            // Shown once even though the real URL appears mid-sentence on a
            // later, unrelated line too - never repeated per domain.
            $this->assertSame(
                1,
                substr_count($text, 'https://www.catsrl.it/shop/legion-9-18iax10-25892?category=1351'),
            );
            $this->assertStringNotContainsString('домен www.catsrl.it:', $text);

            return true;
        });
    }

    public function test_mechanical_training_pings_are_dropped_but_decisions_and_outcomes_still_show(): void
    {
        // User request (2026-08-27): keep every real step visible, but cut
        // mechanical low-information pings ("раунд N", "строит/исправляет
        // рецепт", web-search connectivity chatter) that repeat many times
        // per source down to nothing, so the log stays short and dry.
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]])]);
        $progress = new TelegramProgressReporter(app(TelegramClient::class), '123');
        $progress->step('1/1 · test step', 60);

        $progress->info('OpenAI ответил; поток Web Search подключён.');
        $progress->info('Web Search запущен.');
        $progress->info('Web Search ищет точные страницы товара.');
        $progress->info('Web Search завершён; анализирую результаты.');
        $progress->info('AI-тренер: Playwright собирает DOM, интерактивные элементы и сетевые изображения delkom.pl.');
        $progress->info('AI-тренер: gpt-5.4 строит безопасный JSON-рецепт.');
        $progress->info('AI-тренер: проверяю рецепт, раунд 1');
        $progress->info('AI-тренер: gpt-5.4 исправляет рецепт по DOM и результату предыдущего раунда.');
        $progress->info('AI-тренер: проверяю рецепт, раунд 2');
        $progress->info('AI-рецепт проверен и опубликован. Фото: 16.');
        $progress->done('поиск завершён');

        Http::assertSent(function ($request): bool {
            $text = (string) ($request['text'] ?? '');

            if (! str_ends_with($request->url(), '/editMessageText') || ! str_contains($text, 'поиск завершён')) {
                return false;
            }

            $this->assertStringNotContainsString('Web Search', $text);
            $this->assertStringNotContainsString('OpenAI ответил', $text);
            $this->assertStringNotContainsString('собирает DOM', $text);
            $this->assertStringNotContainsString('строит безопасный JSON-рецепт', $text);
            $this->assertStringNotContainsString('проверяю рецепт, раунд', $text);
            $this->assertStringNotContainsString('исправляет рецепт по DOM', $text);
            $this->assertStringContainsString('AI-рецепт проверен и опубликован. Фото: 16.', $text);

            return true;
        });
    }
}
