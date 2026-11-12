<?php

// Read-only: what state is the bot folder in? Nothing is written anywhere.
// Usage: php bot-state.php [path to the bot folder]
// Without an argument the bot folder is the main worktree of this repository.

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$bot = $argv[1] ?? null;
if ($bot === null) {
    $list = (string) shell_exec('git -C '.escapeshellarg(dirname(__DIR__, 4)).' worktree list --porcelain');
    $bot = preg_match('/^worktree (.+)$/m', $list, $match) === 1 ? trim($match[1]) : null;
}
if ($bot === null || ! is_file($bot.'/artisan')) {
    exit("the bot folder was not found\n");
}
echo 'bot folder: '.$bot."\n";
echo 'version: '.trim((string) shell_exec('git -C '.escapeshellarg($bot).' log -1 --format="%h %s"'))."\n";
$branch = trim((string) shell_exec('git -C '.escapeshellarg($bot).' branch --show-current'));
echo 'branch: '.($branch === '' ? '(not on a branch)' : $branch)."\n";
$changes = trim((string) shell_exec('git -C '.escapeshellarg($bot).' status --porcelain'));
echo 'local changes: '.($changes === '' ? 'none' : count(explode("\n", $changes)).' file(s)')."\n";

chdir($bot);
require $bot.'/vendor/autoload.php';
$app = require $bot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $open = DB::table('telegram_updates')->whereIn('status', ['pending', 'queued', 'processing', 'received', 'transcribed', 'delivering'])
        ->whereNull('processed_at')->orderByDesc('id')->limit(5)->get(['id', 'status', 'created_at']);
    echo 'requests in work: '.$open->count()."\n";
    foreach ($open as $row) {
        echo "  #{$row->id} {$row->status} since {$row->created_at}\n";
    }
    echo 'jobs in the queue: '.(Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0)."\n";
    $last = DB::table('telegram_updates')->orderByDesc('id')->first(['id', 'status', 'created_at']);
    echo 'last request: '.($last ? "#{$last->id} {$last->status} at {$last->created_at}" : 'none')."\n";
    $ran = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
    $files = array_map(fn (string $file): string => basename($file, '.php'), glob($bot.'/database/migrations/*.php') ?: []);
    $pending = array_values(array_diff($files, $ran));
    echo 'pending migrations: '.count($pending).($pending === [] ? '' : ' ('.implode(', ', array_slice($pending, 0, 4)).')')."\n";
} catch (Throwable $exception) {
    echo 'could not read the database: '.mb_substr($exception->getMessage(), 0, 200)."\n";
}
