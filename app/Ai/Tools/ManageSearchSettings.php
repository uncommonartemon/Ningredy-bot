<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductGalleryRecipeRouter;
use App\Services\Products\ProductSourcePriority;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * The search's own settings from the chat (live 2026-11-02, a colleague: «не могу
 * заблокировать сайт или задать бюджет» was the bot's answer). Banning a site goes
 * through the same writer as the Telegram button and the admin; settings through
 * the same setters and limits as «Настройки AI». Keys and logins stay in the admin.
 */
class ManageSearchSettings implements Tool
{
    use RecordsOperations;

    /** Setting => [validation, what it is]. */
    private const array SETTINGS = [
        'max_search_cost_usd' => ['numeric|min:0|max:1000', 'Бюджет одного поиска в долларах; 0 — без денежного ограничения.'],
        'search_max_seconds' => ['integer|min:300|max:1800', 'Максимум всего цикла поиска, секунд (300–1800).'],
        'product_search_country' => ['nullable|regex:/^[A-Za-z]{2}$/', 'Страна веб-поиска, две буквы (CZ, UA, DE); пусто — весь мир.'],
        'fallback_sources_enabled' => ['boolean', 'Резервный платный AI веб-поиск по новым магазинам, когда известные не дали фото.'],
        'stronger_model_enabled' => ['boolean', 'Более сильная модель для сложных шагов.'],
        'photo_caption_routing' => ['in:label,manual,search', 'Фото с подписью без слова «найди»: label — бот сначала читает фото, наклейка с кодом = поиск, иначе ручная загрузка; '
            .'manual — всегда ручная загрузка; search — всегда поиск. Подпись с ценой, состоянием («б/у») или «не ищи» всегда означает свой товар.'],
    ];

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Search settings and banned sites, from the chat. action=show returns the current values and every banned site; '
            .'action=set changes one setting (max_search_cost_usd, search_max_seconds, product_search_country, fallback_sources_enabled, stronger_model_enabled, photo_caption_routing); '
            .'action=block_site bans a site from every future search (no page, photo or fact from it), action=unblock_site lifts the ban. '
            .'API keys and logins are not here: they are changed only in the admin.';
    }

    public function handle(Request $request): Stringable|string
    {
        $action = (string) $request->string('action');
        try {
            return $this->json(match ($action) {
                'show' => $this->show(),
                'set' => $this->set((string) $request->string('setting'), $request['value'] ?? null),
                'block_site', 'unblock_site' => $this->site($action, (string) $request->string('site'), trim((string) $request->string('reason'))),
                default => ['ok' => false, 'error' => 'Unknown action.'],
            });
        } catch (Throwable $exception) {
            return $this->json(['ok' => false, 'error' => mb_substr($exception->getMessage(), 0, 800)]);
        }
    }

    private function show(): array
    {
        $settings = app(AiSettings::class);

        return ['ok' => true, 'settings' => [
            'max_search_cost_usd' => $settings->maxSearchCostUsd(),
            'search_max_seconds' => $settings->searchMaxSeconds(),
            'product_search_country' => $settings->productSearchCountry(),
            'fallback_sources_enabled' => $settings->fallbackSourcesEnabled(),
            'stronger_model_enabled' => $settings->strongerModelEnabled(),
            'photo_caption_routing' => $settings->photoCaptionRouting(),
        ], 'meaning' => array_map(fn (array $setting): string => $setting[1], self::SETTINGS),
            'banned_sites' => app(ProductSourcePriority::class)->blockedDomains()];
    }

    private function set(string $setting, mixed $value): array
    {
        if (! array_key_exists($setting, self::SETTINGS)) {
            return ['ok' => false, 'error' => 'Эту настройку из чата не меняют: '.$setting.'. Доступны: '.implode(', ', array_keys(self::SETTINGS)).'.'];
        }
        $value = is_string($value) ? trim($value) : $value;
        $rule = self::SETTINGS[$setting][0];
        if ($value === null || $value === '') {
            return ['ok' => false, 'error' => 'Не указано значение для '.$setting.': '.self::SETTINGS[$setting][1]];
        }
        if ($rule === 'boolean') {
            // The model writes "true"/"false"; the boolean rule itself reads only 1/0.
            $value = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                return ['ok' => false, 'error' => 'Для '.$setting.' нужно значение true или false (включить или выключить).'];
            }
            $rule = 'required|boolean';
        } elseif (is_string($value) && str_starts_with($rule, 'numeric')) {
            $value = str_replace(',', '.', $value);
        }
        Validator::make(['value' => $value], ['value' => $rule])->validate();
        $before = $this->show()['settings'][$setting];

        return $this->recordOperation($this->update, class_basename(self::class), 'set_search_setting',
            ['setting' => $setting, 'value' => $value], function () use ($setting, $value, $before): array {
                $settings = app(AiSettings::class);
                match ($setting) {
                    'max_search_cost_usd' => $settings->saveMaxSearchCostUsd((float) $value),
                    'search_max_seconds' => $settings->saveSearchMaxSeconds((int) $value),
                    'product_search_country' => $settings->saveProductSearchCountry((string) $value),
                    'fallback_sources_enabled' => $settings->saveFallbackSourcesEnabled(filter_var($value, FILTER_VALIDATE_BOOLEAN)),
                    'stronger_model_enabled' => $settings->saveStrongerModelEnabled(filter_var($value, FILTER_VALIDATE_BOOLEAN)),
                    'photo_caption_routing' => $settings->savePhotoCaptionRouting((string) $value),
                };

                return ['setting' => $setting, 'before' => $before, 'after' => $this->show()['settings'][$setting]];
            });
    }

    private function site(string $action, string $site, string $reason): array
    {
        $host = ProductSourcePriority::host(str_contains($site, '://') ? $site : 'https://'.trim($site));
        if ($host === '' || ! str_contains($host, '.')) {
            return ['ok' => false, 'error' => 'Не понял, какой сайт: '.$site];
        }

        return $this->recordOperation($this->update, class_basename(self::class), $action,
            ['site' => $host, 'reason' => $reason], function () use ($action, $host, $reason): array {
                $router = app(ProductGalleryRecipeRouter::class);
                $action === 'block_site'
                    ? $router->blockDomain($host, 'Заблокировано оператором в чате Telegram'.($reason !== '' ? ': '.$reason : '.'))
                    : $router->unblockDomain($host);
                // The real state after the write, not the intent: a site blocked by several
                // of its own path rows stays blocked when there is no domain row to lift.
                $banned = app()->make(ProductSourcePriority::class)->isBlockedUrl('https://'.$host);

                return ['site' => $host, 'banned' => $banned, 'message' => $banned === ($action === 'block_site')
                    ? ($banned ? 'Сайт заблокирован для всех будущих поисков.' : 'Блокировка снята.')
                    : 'Сайт остаётся заблокированным: его блокируют отдельные страницы, а не весь домен. Скажи это оператору; снять такие блокировки можно в админке, в рецептах галерей.'];
            });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['show', 'set', 'block_site', 'unblock_site'])->required(),
            'setting' => $schema->string()->enum(array_keys(self::SETTINGS))->description('For action=set.'),
            'value' => $schema->string()->description('For action=set: the new value as text ("0.5", "900", "CZ", "true").'),
            'site' => $schema->string()->description('For block_site/unblock_site: the site or any address on it.'),
            'reason' => $schema->string()->max(500)->description('For block_site: why, in the operator\'s words.'),
        ];
    }
}
