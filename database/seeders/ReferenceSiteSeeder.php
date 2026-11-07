<?php

namespace Database\Seeders;

use App\Models\ReferenceSite;
use Illuminate\Database\Seeder;

/**
 * Starting reference sites for components, checked 2026-11-02: none of the bot
 * sites forbids OpenAI's crawlers in robots.txt (the bot finds pages through
 * OpenAI's web search); TechPowerUp forbids every bot and answers one with a
 * captcha, so it is kept for a person. Data, not logic: nothing branches on a
 * site; the operator edits these rows in «Справочники» and the bot adds its own
 * from the chat. Runs once, from the table's migration: a row the operator
 * deleted stays deleted.
 */
class ReferenceSiteSeeder extends Seeder
{
    private const string FACTS_ONLY = 'Брать только характеристики именно этой модели (её код или полное название на странице); фото с этого сайта не брать.';

    /** The maker's own pages keep their photos: what the pipeline already took from them stays allowed. */
    private const string MAKER = 'Официальные характеристики производителя. Брать данные только этой модели: её код или полное название должно быть на странице.';

    public const array SITES = [
        ['domain' => 'harddrivebenchmark.net', 'applies_to' => 'SSD и жёсткие диски (HDD), в том числе OEM-накопители из ноутбуков',
            'search_hint' => 'site:harddrivebenchmark.net {code}', 'sort_order' => 10,
            'instructions' => 'PassMark: средние скорости, измеренные пользователями (последовательное чтение и запись, IOPS). Это измерения, а не паспорт производителя — так и подписывать. '.self::FACTS_ONLY],
        ['domain' => 'notebookcheck.net', 'applies_to' => 'SSD, процессоры и видеокарты ноутбуков',
            'search_hint' => 'site:notebookcheck.net {code}', 'sort_order' => 20,
            'instructions' => 'Обзоры и тесты комплектующих ноутбуков. '.self::FACTS_ONLY],
        ['domain' => 'semiconductor.samsung.com', 'applies_to' => 'SSD и память Samsung, в том числе OEM (коды MZ…, PM9…, M4…)',
            'search_hint' => 'site:semiconductor.samsung.com {code}', 'sort_order' => 30, 'use_for_photos' => true, 'instructions' => self::MAKER],
        ['domain' => 'micron.com', 'applies_to' => 'Память и SSD Micron и Crucial (коды MT…, MTFD…, CT…)',
            'search_hint' => 'site:micron.com {code}', 'sort_order' => 40, 'use_for_photos' => true, 'instructions' => self::MAKER],
        ['domain' => 'skhynix.com', 'applies_to' => 'Память и SSD SK hynix (коды HFM…, HFS…, HMA…, HMCG…)',
            'search_hint' => 'site:skhynix.com {code}', 'sort_order' => 50, 'use_for_photos' => true, 'instructions' => self::MAKER],
        ['domain' => 'intel.com', 'applies_to' => 'Процессоры Intel',
            'search_hint' => 'site:intel.com {model} specifications', 'sort_order' => 60, 'use_for_photos' => true,
            'instructions' => 'Intel ARK — официальные характеристики процессоров. '.self::MAKER],
        ['domain' => 'amd.com', 'applies_to' => 'Процессоры и видеокарты AMD',
            'search_hint' => 'site:amd.com {model} specifications', 'sort_order' => 70, 'use_for_photos' => true, 'instructions' => self::MAKER],
        ['domain' => 'nvidia.com', 'applies_to' => 'Видеокарты NVIDIA (характеристики графического чипа)',
            'search_hint' => 'site:nvidia.com {model} specifications', 'sort_order' => 80, 'use_for_photos' => true,
            'instructions' => 'Характеристики чипа. У карт ASUS, MSI, Gigabyte и других частоты, память и охлаждение свои — их брать со страницы самой карты; фото NVIDIA — только для её собственных карт Founders Edition.'],
        ['domain' => 'techpowerup.com', 'applies_to' => 'SSD (база SSD TechPowerUp: контроллер, память, скорости)', 'access' => ReferenceSite::ACCESS_PERSON,
            'link_template' => 'https://www.techpowerup.com/ssd-specs/?q={model}', 'sort_order' => 90,
            'instructions' => 'Сайт закрыт для ботов: запрет в robots.txt и капча. Бот его не открывает, а даёт ссылку оператору; оператор может прислать скриншот или текст.'],
        ['domain' => 'techpowerup.com', 'applies_to' => 'Видеокарты (база GPU TechPowerUp)', 'access' => ReferenceSite::ACCESS_PERSON,
            'link_template' => 'https://www.techpowerup.com/gpu-specs/?q={model}', 'sort_order' => 91,
            'instructions' => 'Сайт закрыт для ботов: запрет в robots.txt и капча. Бот его не открывает, а даёт ссылку оператору.'],
        ['domain' => 'techpowerup.com', 'applies_to' => 'Процессоры (база CPU TechPowerUp)', 'access' => ReferenceSite::ACCESS_PERSON,
            'link_template' => 'https://www.techpowerup.com/cpu-specs/?q={model}', 'sort_order' => 92,
            'instructions' => 'Сайт закрыт для ботов: запрет в robots.txt и капча. Бот его не открывает, а даёт ссылку оператору.'],
    ];

    public function run(): void
    {
        foreach (self::SITES as $site) {
            ReferenceSite::query()->firstOrCreate(
                ['domain' => $site['domain'], 'applies_to' => $site['applies_to']],
                ['use_for_facts' => true, 'use_for_photos' => false, 'access' => ReferenceSite::ACCESS_BOT, 'enabled' => true, ...$site],
            );
        }
    }
}
