<?php

namespace App\Services\ProductSearch\Codes;

use App\Models\AppSetting;
use App\Models\ProductSourceAttempt;
use Throwable;

/**
 * The databases the bot looks a product up in by its code (Icecat, UPCitemdb):
 * whether they are connected, how they did lately, and what exists for the
 * components they do not cover. Live 2026-11-02: on another machine Icecat had
 * never been connected (no ICECAT_USERNAME in its .env) - every lookup answered
 * "not configured" and nothing anywhere said so.
 */
final class CodeDatabases
{
    /** An Open Icecat login set in the admin; it outranks ICECAT_USERNAME in .env. */
    public const SETTING_ICECAT_USERNAME = 'services.icecat.username';

    public const ICECAT_REGISTER_URL = 'https://icecat.biz/register';

    /**
     * What exists for each kind of component, checked 2026-11-02 against Open Icecat
     * and the services themselves. Advice for the operator, not a list the bot reads.
     *
     * @var array<string, array{title: string, text: string}>
     */
    public const COMPONENT_ADVICE = [
        'ssd' => ['title' => 'SSD и жёсткие диски',
            'text' => 'Icecat (бесплатный): Samsung, Kingston, Seagate есть; WD и Crucial — только в платном Full Icecat. OEM-диски из ноутбуков '
                .'(Samsung PM991, SK hynix BC501) в Icecat почти не бывают: характеристики и скорости — notebookcheck.net, harddrivebenchmark.net '
                .'и сайты производителей чипов. API у них нет — бот находит эти страницы веб-поиском.'],
        'cpu' => ['title' => 'Процессоры',
            'text' => 'Intel и AMD в бесплатном Icecat закрыты (нужен Full Icecat). Официальные характеристики каждой модели — Intel ARK '
                .'(ark.intel.com) и страницы AMD; публичного API у Intel нет, только страницы и выгрузка в Excel вручную.'],
        'gpu' => ['title' => 'Видеокарты',
            'text' => 'В бесплатном Icecat видеокарт мало. Самая полная база — TechPowerUp GPU Database, но она закрыта для ботов; '
                .'характеристики чипов — на сайтах NVIDIA и AMD, карт — на сайтах ASUS, MSI, Gigabyte.'],
        'ram' => ['title' => 'Оперативная память',
            'text' => 'Icecat: Kingston и Samsung есть, Crucial — только в Full Icecat. Серверная и OEM-память (Micron, SK hynix) — '
                .'по коду на сайтах производителей.'],
        'distributors' => ['title' => 'Дистрибьюторы с API (Mouser, DigiKey, Octopart)',
            'text' => 'API с характеристиками и фото есть, бесплатно с лимитами, но это промышленные детали: ноутбучных OEM-дисков и '
                .'памяти там нет (проверено по кодам PM991a, BC501, Micron RDIMM). Не подключены.'],
    ];

    /** The Open Icecat login the bot uses: the admin's, else ICECAT_USERNAME from .env. */
    public static function icecatUsername(): ?string
    {
        try {
            $stored = trim((string) AppSetting::valueFor(self::SETTING_ICECAT_USERNAME));
        } catch (Throwable) {
            $stored = '';
        }
        $username = $stored !== '' ? $stored : trim((string) config('services.icecat.username'));

        return $username !== '' ? $username : null;
    }

    /** Where the login comes from: 'admin', 'env' or null when there is none. */
    public static function icecatUsernameSource(): ?string
    {
        try {
            if (trim((string) AppSetting::valueFor(self::SETTING_ICECAT_USERNAME)) !== '') {
                return 'admin';
            }
        } catch (Throwable) {
            // No settings table yet: only .env can say.
        }

        return trim((string) config('services.icecat.username')) !== '' ? 'env' : null;
    }

    public static function saveIcecatUsername(?string $username): void
    {
        $username = trim((string) $username);
        AppSetting::put(self::SETTING_ICECAT_USERNAME, $username !== '' ? $username : null);
    }

    /**
     * Lookups of the last days per database, as decision => count, from the journal.
     *
     * @return array<string, array<string, int>>
     */
    public static function stats(int $days = 7): array
    {
        $stats = [];
        foreach (ProductCodeLookup::SOURCES as $source) {
            $stats[$source] = ProductSourceAttempt::query()->where('phase', 'code_lookup')->where('action', 'lookup_'.$source)
                ->where('created_at', '>=', now()->subDays($days))
                ->selectRaw('decision, count(*) as total')->groupBy('decision')->pluck('total', 'decision')
                ->map(fn (mixed $total): int => (int) $total)->all();
        }

        return $stats;
    }
}
