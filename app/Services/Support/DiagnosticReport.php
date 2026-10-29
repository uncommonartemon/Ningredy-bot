<?php

namespace App\Services\Support;

use App\Models\AiRun;
use App\Models\ImageRenditionRule;
use App\Models\ImageRenditionRuleOutcome;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\ProductSearch\SearchJournal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Everything a developer needs to see what this installation's bot did, in one
 * file the operator can send: the searches of the last days with their outcome
 * and step-by-step story, the raw journal, the AI calls' accounting, the cards,
 * what the bot learned about sites, the error log and the shape of the
 * settings. Secrets never leave the machine (operator, 2026-10-29: the bot is
 * handed to a person; what goes wrong there has to reach the developer).
 */
final class DiagnosticReport
{
    /** Cut inside input/output objects of the journal: pages' texts are not what a report is for. */
    private const STRING_LIMIT = 4000;

    private const LOG_TAIL_BYTES = 2_097_152;

    /**
     * @param  bool  $withDatabase  also a consistent copy of the database with its secrets blanked
     * @param  string|null  $connection  the connection to copy; the application's by default
     * @return string the absolute path of the zip that was written
     */
    public function build(int $days = 7, int $maxRequests = 80, bool $withDatabase = false, ?string $connection = null): string
    {
        $days = max(1, $days);
        $snapshot = null;
        $updates = TelegramUpdate::query()->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('id')->limit(max(1, $maxRequests))->get()->sortBy('id')->values();
        $ids = $updates->pluck('id')->all();

        $directory = storage_path('app/reports');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/ningredy-report-'.now()->format('Ymd-His').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create the report archive at {$path}.");
        }
        try {
            $zip->addFromString('summary.txt', $this->summary($days, $updates));
            foreach ($updates as $update) {
                $zip->addFromString("requests/{$update->id}.txt", $this->request($update));
            }
            $zip->addFromString('journal.jsonl', $this->rows(
                ProductSourceAttempt::query()->whereIn('telegram_update_id', $ids)->orderBy('id'),
                ['id', 'telegram_update_id', 'product_draft_id', 'created_at', 'actor', 'phase', 'action', 'status', 'decision',
                    'domain', 'product_url', 'message', 'input', 'output'],
            ));
            $zip->addFromString('ai_runs.jsonl', $this->rows(
                AiRun::query()->whereIn('telegram_update_id', $ids)->orderBy('id'),
                ['id', 'telegram_update_id', 'provider', 'model', 'status', 'activity', 'usage', 'error', 'started_at', 'completed_at'],
            ));
            $zip->addFromString('drafts.jsonl', $this->drafts($ids));
            $zip->addFromString('learned/rendition_rules.json', $this->json([
                'rules' => ImageRenditionRule::query()->orderBy('position')->orderBy('id')->get()->toArray(),
                'outcomes' => ImageRenditionRuleOutcome::query()->orderBy('image_rendition_rule_id')->get()->toArray(),
            ]));
            $zip->addFromString('learned/recipes.json', $this->json(
                ProductGalleryRecipe::query()->orderBy('domain')->get()->map(fn (ProductGalleryRecipe $recipe): array => $recipe->toArray())->all(),
            ));
            foreach ($this->logs() as $name => $tail) {
                $zip->addFromString("logs/{$name}", $tail);
            }
            $zip->addFromString('env.redacted.txt', $this->envShape());
            if ($withDatabase) {
                $snapshot = $this->databaseSnapshot($connection);
                $zip->addFile($snapshot, 'database.sqlite');
            }
        } finally {
            $zip->close();
            if ($snapshot !== null) {
                @unlink($snapshot);
            }
        }

        return $path;
    }

    /**
     * A consistent copy of the database with its secrets blanked: the developer's
     * own tools run against it as against a live one (operator, 2026-10-29: "бд
     * просто подойдёт?"). Keys, tokens and proxy addresses stay on the machine.
     */
    private function databaseSnapshot(?string $connection = null): string
    {
        $path = storage_path('app/reports/snapshot-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite');
        // SQLite refuses VACUUM inside a transaction; the job, the admin button and
        // bot:report run outside one.
        DB::connection($connection)->statement('VACUUM INTO ?', [$path]);
        $copy = new PDO('sqlite:'.$path);
        $hasSettings = $copy->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'app_settings'")?->fetchColumn();
        if ($hasSettings) {
            $copy->exec("UPDATE app_settings SET value = '' WHERE key LIKE 'ai.keys.%' OR key LIKE '%token%' OR key LIKE '%secret%'"
                ." OR key LIKE '%password%' OR key LIKE '%api_key%' OR key LIKE '%proxy%'");
        }
        $copy = null;

        return $path;
    }

    /** @param  Collection<int, TelegramUpdate>  $updates */
    private function summary(int $days, Collection $updates): string
    {
        $lines = [
            'Ningredy diagnostic report',
            'generated: '.now()->toDateTimeString().' ('.config('app.timezone').')',
            'version: '.$this->version(),
            'php: '.PHP_VERSION.' · os: '.PHP_OS_FAMILY.' · laravel: '.app()->version(),
            "window: last {$days} days · requests: ".$updates->count(),
            '',
        ];
        try {
            Artisan::call('search:stats', ['--days' => $days]);
            $lines[] = trim(Artisan::output());
        } catch (Throwable $exception) {
            $lines[] = 'search:stats failed: '.$exception->getMessage();
        }

        return $this->redact(implode("\n", $lines)."\n");
    }

    private function request(TelegramUpdate $update): string
    {
        $draft = ProductDraft::query()->where('telegram_update_id', $update->id)->latest('id')->first();
        $lines = [
            "#{$update->id} · ".($update->text !== null && trim((string) $update->text) !== '' ? trim((string) $update->text) : '(voice or photo)'),
            'status: '.$update->status.' · created: '.$update->created_at?->toDateTimeString().' · processed: '.($update->processed_at?->toDateTimeString() ?? '-'),
        ];
        if ($draft !== null) {
            $lines[] = "draft #{$draft->id}: ".trim((string) $draft->title).' · status '.$draft->status
                .' · gallery '.($draft->gallery_status ?? '-').' · stop '.($draft->gallery_search_stop_reason ?? '-')
                .' · photos '.$draft->media()->count();
        }
        $lines[] = '';

        return $this->redact(implode("\n", [...$lines, ...SearchJournal::timeline($update->id, 2000)])."\n");
    }

    /** @param  list<string>  $fields */
    private function rows(Builder $query, array $fields): string
    {
        $out = '';
        foreach ($query->cursor() as $row) {
            $values = [];
            foreach ($fields as $field) {
                $values[$field] = $this->trim($row->{$field});
            }
            $out .= $this->json($values, pretty: false)."\n";
        }

        return $this->redact($out);
    }

    /** @param  list<int>  $ids */
    private function drafts(array $ids): string
    {
        $out = '';
        foreach (ProductDraft::query()->whereIn('telegram_update_id', $ids)->orderBy('id')->cursor() as $draft) {
            $out .= $this->json([
                'id' => $draft->id, 'telegram_update_id' => $draft->telegram_update_id, 'title' => $draft->title,
                'brand' => $draft->brand, 'model' => $draft->model, 'color' => $draft->color, 'status' => $draft->status,
                'gallery_status' => $draft->gallery_status, 'gallery_search_stop_reason' => $draft->gallery_search_stop_reason,
                'primary_source_url' => $draft->primary_source_url,
                'photos' => $draft->media()->get(['source_url', 'width', 'height', 'verification_status'])->toArray(),
                'created_at' => $draft->created_at, 'updated_at' => $draft->updated_at,
            ], pretty: false)."\n";
        }

        return $this->redact($out);
    }

    /** @return array<string, string> file name => the end of the file, newest two logs */
    private function logs(): array
    {
        $directory = storage_path('logs');
        if (! is_dir($directory)) {
            return [];
        }
        $files = collect(File::files($directory))
            ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.log'))
            ->sortByDesc(fn ($file): int => $file->getMTime())->take(2);
        $logs = [];
        foreach ($files as $file) {
            $size = $file->getSize();
            $handle = fopen($file->getPathname(), 'rb');
            if ($handle === false) {
                continue;
            }
            if ($size > self::LOG_TAIL_BYTES) {
                fseek($handle, -self::LOG_TAIL_BYTES, SEEK_END);
            }
            $logs[$file->getFilename()] = $this->redact(($size > self::LOG_TAIL_BYTES ? '[... first '.($size - self::LOG_TAIL_BYTES)." bytes left out]\n" : '')
                .(string) stream_get_contents($handle));
            fclose($handle);
        }

        return $logs;
    }

    /** Which settings are set and how long they are - never what they are. */
    private function envShape(): string
    {
        $lines = ['# keys of .env with the length of each value; values stay on the machine'];
        foreach (preg_split('/\R/', (string) @file_get_contents(base_path('.env'))) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=(.*)$/', $line, $match) !== 1) {
                continue;
            }
            $value = trim($match[2], " \t\"'");
            $lines[] = $match[1].'='.($value === '' ? '' : '<set, '.mb_strlen($value).' chars>');
        }

        return implode("\n", $lines)."\n";
    }

    /** The commit the code runs at, read from .git without running git. */
    private function version(): string
    {
        $head = trim((string) @file_get_contents(base_path('.git/HEAD')));
        if (str_starts_with($head, 'ref: ')) {
            $ref = trim((string) @file_get_contents(base_path('.git/'.substr($head, 5))));
            if ($ref === '' && preg_match('/^'.preg_quote(substr($head, 5), '/').' |(?<=\s)'.preg_quote(substr($head, 5), '/').'$/m',
                (string) @file_get_contents(base_path('.git/packed-refs')), $match) !== 1) {
                return 'unknown ('.$head.')';
            }
            if ($ref === '') {
                foreach (preg_split('/\R/', (string) @file_get_contents(base_path('.git/packed-refs'))) ?: [] as $line) {
                    if (str_ends_with($line, ' '.substr($head, 5))) {
                        $ref = substr($line, 0, 40);
                    }
                }
            }

            return ($ref !== '' ? substr($ref, 0, 12) : 'unknown').' ('.substr($head, 5).')';
        }

        return $head !== '' ? substr($head, 0, 12) : 'unknown';
    }

    private function trim(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > self::STRING_LIMIT ? mb_substr($value, 0, self::STRING_LIMIT).'…[cut]' : $value;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->trim($item), $value);
        }

        return $value;
    }

    private function json(mixed $value, bool $pretty = true): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0));
    }

    /** @var list<string>|null */
    private ?array $secrets = null;

    /**
     * Every secret this installation holds: the Telegram token, the Icecat account,
     * the provider keys of config/ai.php and the keys entered in the admin panel.
     *
     * @return list<string>
     */
    private function secrets(): array
    {
        if ($this->secrets !== null) {
            return $this->secrets;
        }
        $values = [config('services.telegram.bot_token'), config('services.icecat.username')];
        foreach ((array) config('ai.providers', []) as $provider) {
            $values[] = is_array($provider) ? ($provider['key'] ?? null) : null;
        }
        try {
            $values = [...$values, ...DB::table('app_settings')->where('key', 'like', 'ai.keys.%')->pluck('value')->all()];
        } catch (Throwable) {
            // Before the settings table exists the file settings are all there is.
        }

        return $this->secrets = array_values(array_unique(array_filter($values,
            fn (mixed $value): bool => is_string($value) && mb_strlen($value) >= 8)));
    }

    /** Tokens and keys the text may carry, whatever wrote them there. */
    private function redact(string $text): string
    {
        foreach ($this->secrets() as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        return (string) preg_replace(['/\b\d{6,}:[A-Za-z0-9_-]{30,}/', '/\bsk-[A-Za-z0-9_-]{16,}/'], '[redacted]', $text);
    }
}
