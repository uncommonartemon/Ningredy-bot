<?php

namespace App\Console\Commands;

use App\Jobs\TranslateCatalogNames;
use App\Services\Catalog\CatalogNames;
use Illuminate\Console\Command;

/**
 * Queues the translation of category, group and characteristic names that lack a language.
 * The scheduler runs it; with nothing missing it does nothing and costs nothing.
 */
class TranslateCatalogNamesCommand extends Command
{
    protected $signature = 'catalog:translate-names {--again : Also ask for what an earlier request left unfilled}';

    protected $description = 'Queue the translation of catalog names that lack a language';

    public function handle(CatalogNames $names): int
    {
        $missing = count($names->missing());
        $this->info('Языки названий: '.implode(', ', CatalogNames::locales()).'. Названий без перевода: '.$missing.'.');
        if ($missing > 0) {
            TranslateCatalogNames::dispatch((bool) $this->option('again'));
        }

        return self::SUCCESS;
    }
}
