<?php

namespace Database\Seeders;

use App\Models\ProductSourceDomain;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Starting operator knowledge for domains whose gallery behaviour is known to
 * be non-obvious. This is data, not logic: nothing in the pipeline branches on
 * these domains, the trainer only reads whatever text happens to sit in
 * agent_hint. Shipping it as a seeder rather than a migration keeps it
 * re-runnable, environment-independent and - most importantly - freely
 * editable afterwards from Filament or from the Telegram "retrain with a hint"
 * button, which a migration is not.
 *
 * Existing text is never overwritten: once an operator has curated a domain's
 * hint, that is the authority.
 */
class ProductSourceDomainHintSeeder extends Seeder
{
    use WithoutModelEvents;

    /** @var array<string, string> */
    private const HINTS = [
        // Keyed by the www-stripped host, matching ProductSourcePriority::host().
        'bhphotovideo.com' => 'Открытый media viewer сам по себе ещё не даёт полный размер: выбранная миниатюра '
            .'остаётся рендишеном 750x750. Чтобы получить крупный файл, внутри уже открытого viewer нажми на само '
            .'изображение (не на миниатюру) или на observed zoom-контрол - только после этого сайт запрашивает '
            .'рендишен 2000x2000. Выбор новой миниатюры сбрасывает кадр обратно на 750x750, поэтому увеличивать '
            .'нужно каждый кадр отдельно. Проверяй улучшение по DOM или по сетевым image URL, а не по факту клика.',
    ];

    /**
     * Values this seeder itself shipped in an earlier release. An installation
     * still carrying one verbatim has never been curated, so replacing it is
     * an upgrade rather than overwriting somebody's work.
     *
     * @var array<int, string>
     */
    private const SUPERSEDED = [
        'После открытия основного media viewer/lightbox проверь вложенный уровень увеличения: нажми на главное изображение или zoom-контрол внутри уже открытого viewer. B&H часто только после этого запрашивает более крупную rendition. Подтверди улучшение по DOM и сетевым image URL; не считай страничные миниатюры финальным разрешением, если доступен вложенный zoom.',
        'Open the main media viewer, then its nested zoom. Selecting every new thumbnail resets that frame to a 750x750 rendition. After EACH thumbnail, use click_each.after_each_selector to press the observed zoom-plus until no change, and collect the observed 2000x2000 URL. Do not declare full success from images750x750 while zoom-plus is available. Verify the improvement in DOM or image-network URLs.',
    ];

    public function run(): void
    {
        foreach (self::HINTS as $domain => $hint) {
            $settings = ProductSourceDomain::query()->firstOrCreate(['domain' => $domain]);
            $current = trim((string) $settings->agent_hint);

            if ($current !== '' && ! in_array($current, self::SUPERSEDED, true)) {
                continue;
            }

            $settings->update(['agent_hint' => $hint]);
        }
    }
}
