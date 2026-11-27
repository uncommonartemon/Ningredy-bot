<?php

namespace App\Services;

use App\Models\AiRun;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\CatalogLocales;
use App\Services\Products\CatalogTextTranslator;
use App\Services\ProductSearch\Codes\CodeDatabases;
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
            $this->dictionaries(),
            $this->codeDatabases(),
            $this->translations(),
        ];
    }

    /**
     * Cards still lacking a shop language. A translation that failed or never ran left the
     * card English on the Czech and Ukrainian pages, and nothing anywhere said so.
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    private function translations(): array
    {
        if (CatalogLocales::contentTranslations() === []) {
            return $this->result('translations', 'Переводы', 'off', 'Карточки не переводятся: в «Языках каталога» не отмечен ни один язык.', '');
        }
        $pending = app(CatalogTextTranslator::class)->pendingProductIds();
        if ($pending === []) {
            return $this->result('translations', 'Переводы', 'up', 'Все товары переведены на языки магазина.', '');
        }

        return $this->result('translations', 'Переводы', 'down',
            'Товаров без перевода на языки магазина: '.count($pending).' ('.implode(', ', array_map(fn (int $id): string => '#'.$id, array_slice($pending, 0, 10)))
            .(count($pending) > 10 ? ', …' : '').').',
            'Бот сам переводит их каждые 10 минут, а что не перевелось — снова через 6 часов. Сразу: «Перевести недостающее» в «Настройки → Языки каталога».');
    }

    /**
     * Icecat answers most product lookups by code for free; without its login the bot
     * silently searches the web for what one call would give (live 2026-11-02: another
     * machine had never had ICECAT_USERNAME, and every lookup said "not configured").
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    private function codeDatabases(): array
    {
        if (! CodeDatabases::enabled('icecat')) {
            return $this->result('code_databases', 'Сервисы', 'off',
                'Icecat выключен оператором: бот ищет без него, по остальным источникам.', 'Включить: «Настройки → Сервисы».');
        }
        $source = CodeDatabases::icecatUsernameSource();
        if ($source === null) {
            return $this->result('code_databases', 'Сервисы', 'down',
                'Icecat не подключён: бот ищет по кодам только в интернете, без бесплатной базы характеристик и фото.',
                'Зарегистрируйтесь бесплатно в Open Icecat и впишите логин: «Настройки → Сервисы».');
        }
        if (CodeDatabases::lastIcecatAnswer() === 'bad_login') {
            return $this->result('code_databases', 'Сервисы', 'down',
                'Icecat не принял логин: на каждый код он отвечает, что такого пользователя нет.',
                'Проверьте логин кнопкой «Проверить логин»: «Настройки → Сервисы».');
        }
        $found = (int) (CodeDatabases::stats()['icecat']['found'] ?? 0);
        $total = array_sum(CodeDatabases::stats()['icecat'] ?? []);

        return $this->result('code_databases', 'Сервисы', 'up',
            'Icecat подключён (логин '.($source === 'admin' ? 'из админки' : 'из .env').'). За 7 дней найдено '.$found.' из '.$total.' запросов.', '');
    }

    /**
     * The product search sorts every product into an active category; with none
     * it cannot answer at all (live 2026-11-01: after a cleanup of the dictionaries
     * every search on another machine died in one second, and nothing said why).
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    private function dictionaries(): array
    {
        $active = Category::query()->where('is_active', true)->count();

        return $active === 0
            ? $this->result('dictionaries', 'Справочники', 'down', 'Нет ни одной активной категории: поиск товара не сможет вернуть ответ.',
                'Запустите update-and-start.bat — стартовые категории создадутся сами, или создайте категорию в разделе «Категории».')
            : $this->result('dictionaries', 'Справочники', 'up', 'Активных категорий: '.$active.'.', '');
    }

    /** @return array{key: string, label: string, state: string, detail: string, hint: string} */
    private function worker(): array
    {
        try {
            $connection = (string) config('queue.default');
            $database = $connection === 'database';
            // The database queue's size() counts the job a worker holds as well;
            // only jobs nobody has taken are waiting.
            $waiting = collect(['assistant', 'default', 'voice', 'media'])
                ->mapWithKeys(fn (string $queue): array => [$queue => $database
                    ? DB::table('jobs')->where('queue', $queue)->whereNull('reserved_at')->count()
                    : Queue::connection($connection)->size($queue)]);
            $total = $waiting->sum();
            $oldestSeconds = $database ? $this->oldestWaitingJobAgeInSeconds() : null;
            $busy = $database ? $this->jobInHand() : null;
            // A live worker gives a job back after retry_after; one held longer
            // was taken by a worker that has stopped since.
            if ($busy !== null && $busy['seconds'] > (int) config('queue.connections.database.retry_after', 2220)) {
                return $this->result(
                    'worker',
                    'Обработчик задач',
                    'down',
                    'Задачу '.($busy['request'] !== null ? 'запроса #'.$busy['request'] : $busy['job']).' взяли '.$this->humanSeconds($busy['seconds'])
                        .' назад и не закончили: обработчик, который её взял, остановился.',
                    'Похоже, воркер не запущен. Запустите start-ningredy.bat или команду: php artisan queue:work --queue=assistant,default',
                );
            }
            $busyText = $busy === null ? '' : 'Обработчик занят '.($busy['request'] !== null ? 'запросом #'.$busy['request'] : 'задачей '.$busy['job'])
                .' уже '.$this->humanSeconds($busy['seconds']).'.';

            // A backlog on its own is just work in progress. A job nobody has
            // picked up for ten minutes while no job is in hand is a worker
            // nobody started - the single most common way a handover looks broken.
            // A job in hand is a running worker: a long search holds the one
            // worker for twenty minutes, and the job queued behind it waits for
            // that, not for somebody to start anything (live report 2026-11-27).
            if ($oldestSeconds !== null && $oldestSeconds > self::STALLED_QUEUE_MINUTES * 60 && $busy === null) {
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
                trim($busyText.' '.($total === 0
                    ? 'Очередь пуста, за сутки обработано запросов: '.$recent.'.'
                    : 'В очереди задач: '.$total.' ('.$waiting->filter()->map(fn (int $size, string $queue): string => $queue.': '.$size)->implode(', ').')'
                        .($oldestSeconds !== null && $oldestSeconds >= 60 ? ', самая старая ждёт '.$this->humanSeconds($oldestSeconds) : '').'.')),
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

    /**
     * How long the oldest job that a worker could take has been waiting. A job a
     * worker holds has `reserved_at` set and is not waiting; a delayed job waits
     * only from the moment it became available.
     */
    private function oldestWaitingJobAgeInSeconds(): ?int
    {
        $now = time();
        $oldest = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', $now)->min('available_at');

        // Laravel stores this as a unix timestamp, and diffInMinutes is signed
        // in Carbon 3 - subtracting the two integers cannot be read backwards.
        return $oldest === null ? null : max(0, $now - (int) $oldest);
    }

    /**
     * The job a worker took longest ago and still holds: the request it serves
     * (read from the job's own properties) and how long it has been at it.
     *
     * @return array{request: ?int, job: string, seconds: int}|null
     */
    private function jobInHand(): ?array
    {
        $job = DB::table('jobs')->whereNotNull('reserved_at')->orderBy('reserved_at')->first(['payload', 'reserved_at']);
        if ($job === null) {
            return null;
        }
        $payload = json_decode((string) $job->payload, true);
        $command = is_array($payload) ? (string) ($payload['data']['command'] ?? '') : '';
        $request = preg_match('/s:\d+:"(?:telegramUpdateId|updateId)";i:(\d+);/', $command, $match) === 1 ? (int) $match[1] : null;
        $name = is_array($payload) ? class_basename((string) ($payload['displayName'] ?? '')) : '';

        return ['request' => $request, 'job' => $name !== '' ? $name : '—', 'seconds' => max(0, time() - (int) $job->reserved_at)];
    }

    private function humanSeconds(int $seconds): string
    {
        return match (true) {
            $seconds >= 3600 => intdiv($seconds, 3600).' ч '.intdiv($seconds % 3600, 60).' мин',
            $seconds >= 60 => intdiv($seconds, 60).' мин',
            default => $seconds.' с',
        };
    }

    /** @return array{key: string, label: string, state: string, detail: string, hint: string} */
    private function result(string $key, string $label, string $state, string $detail, string $hint): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'detail' => $detail, 'hint' => $hint];
    }
}
