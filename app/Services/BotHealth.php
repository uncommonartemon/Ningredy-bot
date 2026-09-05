<?php

namespace App\Services;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Whether the bot is alive, in the terms an operator can act on.
 *
 * "Is it running" is three separate questions that look the same from inside
 * the machine: is a worker draining the queue, can Telegram reach us, and did
 * the last search actually finish. A console window answers none of them once
 * it is closed, which is the first thing anyone does.
 */
class BotHealth
{
    /** A job older than this at the head of the queue means nobody is draining it. */
    private const STALLED_QUEUE_MINUTES = 10;

    public function __construct(private readonly TelegramClient $telegram) {}

    /** @return array<int, array{key: string, label: string, state: string, detail: string, hint: string}> */
    public function checks(): array
    {
        return [
            $this->worker(),
            $this->webhook(),
            $this->lastSearch(),
        ];
    }

    /** @return array{key: string, label: string, state: string, detail: string, hint: string} */
    private function worker(): array
    {
        try {
            $connection = (string) config('queue.default');
            $waiting = collect(['assistant', 'default', 'voice', 'media'])
                ->mapWithKeys(fn (string $queue): array => [$queue => Queue::connection($connection)->size($queue)]);
            $total = $waiting->sum();
            $oldestSeconds = $connection === 'database'
                ? $this->oldestQueuedJobAgeInSeconds()
                : null;

            // A backlog on its own is just work in progress. A backlog whose
            // head has been sitting there for ten minutes is a worker nobody
            // started - the single most common way a handover looks broken.
            if ($oldestSeconds !== null && $oldestSeconds > self::STALLED_QUEUE_MINUTES * 60) {
                return $this->result(
                    'worker',
                    'Обработчик задач',
                    'down',
                    'Задача ждёт '.$this->humanSeconds($oldestSeconds).', её никто не забирает.',
                    'Похоже, воркер не запущен. Запустите start-ningredy.bat или команду: php artisan queue:work --queue=assistant,default',
                );
            }

            $recent = AiRun::query()->where('started_at', '>=', now()->subDay())->count();

            return $this->result(
                'worker',
                'Обработчик задач',
                'up',
                $total === 0
                    ? 'Очередь пуста, за сутки обработано запросов: '.$recent.'.'
                    : 'В очереди задач: '.$total.' ('.$waiting->filter()->map(fn (int $size, string $queue): string => $queue.': '.$size)->implode(', ').').',
                '',
            );
        } catch (Throwable $exception) {
            return $this->result('worker', 'Обработчик задач', 'unknown', $exception->getMessage(), 'Проверьте настройки очереди в .env');
        }
    }

    /** @return array{key: string, label: string, state: string, detail: string, hint: string} */
    private function webhook(): array
    {
        if ((string) config('services.telegram.bot_token') === '') {
            return $this->result(
                'webhook',
                'Связь с Telegram',
                'down',
                'Токен бота не задан.',
                'Впишите TELEGRAM_BOT_TOKEN в .env и перезапустите бота.',
            );
        }

        try {
            $info = $this->telegram->webhookInfo();
            $url = (string) ($info['url'] ?? '');
            $pending = (int) ($info['pending_update_count'] ?? 0);
            $lastError = (string) ($info['last_error_message'] ?? '');
            $lastErrorAt = (int) ($info['last_error_date'] ?? 0);

            if ($url === '') {
                return $this->result(
                    'webhook',
                    'Связь с Telegram',
                    'down',
                    'Адрес не зарегистрирован — Telegram не знает, куда доставлять сообщения.',
                    'Откройте раздел «Telegram» и зарегистрируйте адрес, либо запустите: php artisan telegram:set-webhook',
                );
            }

            // A delivery error older than the last few minutes is usually the
            // restart that has since fixed itself; a fresh one is happening now.
            if ($lastError !== '' && $lastErrorAt > 0 && (time() - $lastErrorAt) < 600) {
                return $this->result(
                    'webhook',
                    'Связь с Telegram',
                    'down',
                    'Telegram не смог доставить сообщение: '.$lastError,
                    'Адрес мог смениться. Если используется ngrok, проверьте, что он запущен, и обновите адрес.',
                );
            }

            // Telegram only reports an error once it has had something to
            // deliver. Between the tunnel dropping and the next message the
            // registered address is simply dead and nothing knows it, so the
            // address is asked directly: our own webhook refuses a request
            // without the secret header, and that refusal is the proof - it
            // means the address reaches this application and not something else.
            $reachable = $this->webhookAddressAnswers($url);

            if ($reachable === false) {
                return $this->result(
                    'webhook',
                    'Связь с Telegram',
                    'down',
                    'Telegram доставляет на '.$url.', но этот адрес сейчас не отвечает.',
                    'Обычно это упавший ngrok: адрес сменился. Запустите ngrok и обновите адрес в разделе «Telegram».',
                );
            }

            return $this->result(
                'webhook',
                'Связь с Telegram',
                'up',
                'Доставка на '.$url.($pending > 0 ? ', в очереди у Telegram: '.$pending : ', очередь пуста').'.'
                    .($reachable === null ? ' Проверить адрес снаружи не удалось.' : ''),
                $pending > 0 ? 'Сообщения ждут доставки — обычно это значит, что сайт сейчас недоступен.' : '',
            );
        } catch (Throwable $exception) {
            return $this->result(
                'webhook',
                'Связь с Telegram',
                'unknown',
                'Telegram не ответил: '.$exception->getMessage(),
                'Проверьте интернет и правильность токена.',
            );
        }
    }

    /** @return array{key: string, label: string, state: string, detail: string, hint: string} */
    private function lastSearch(): array
    {
        try {
            $update = TelegramUpdate::query()->whereNotNull('processed_at')->latest('processed_at')->first();

            if (! $update) {
                return $this->result('search', 'Последний запрос', 'unknown', 'Запросов ещё не было.', '');
            }

            $when = $update->processed_at?->diffForHumans() ?? '';
            $drafts = ProductDraft::query()->where('telegram_update_id', $update->id)->count();

            if ($update->status === 'failed') {
                return $this->result(
                    'search',
                    'Последний запрос',
                    'down',
                    $when.': ошибка — '.mb_substr((string) $update->error, 0, 160),
                    'Подробности в разделе «Запуски AI».',
                );
            }

            return $this->result(
                'search',
                'Последний запрос',
                'up',
                $when.': '.$update->status.($drafts > 0 ? ', черновиков создано: '.$drafts : ''),
                '',
            );
        } catch (Throwable $exception) {
            return $this->result('search', 'Последний запрос', 'unknown', $exception->getMessage(), '');
        }
    }

    /**
     * True when the registered address reaches this application, false when it
     * cannot be reached at all, null when the answer is not ours to read.
     */
    private function webhookAddressAnswers(string $url): ?bool
    {
        try {
            $response = Http::timeout(8)->withoutRedirecting()->post($url, []);

            // 403 is our own controller refusing a request that carries no
            // secret header - the healthiest answer this probe can get. 2xx
            // would mean something else is answering on that address.
            return in_array($response->status(), [403, 401], true);
        } catch (Throwable) {
            return false;
        }
    }

    private function oldestQueuedJobAgeInSeconds(): ?int
    {
        $oldest = DB::table('jobs')->min('created_at');

        // Laravel stores this as a unix timestamp, and diffInMinutes is signed
        // in Carbon 3 - subtracting the two integers cannot be read backwards.
        return $oldest === null ? null : max(0, time() - (int) $oldest);
    }

    private function humanSeconds(int $seconds): string
    {
        return $seconds >= 3600
            ? intdiv($seconds, 3600).' ч '.intdiv($seconds % 3600, 60).' мин'
            : intdiv($seconds, 60).' мин';
    }

    /** @return array{key: string, label: string, state: string, detail: string, hint: string} */
    private function result(string $key, string $label, string $state, string $detail, string $hint): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'detail' => $detail, 'hint' => $hint];
    }
}
