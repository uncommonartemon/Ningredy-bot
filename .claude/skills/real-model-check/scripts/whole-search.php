<?php

// One real search from the request to the draft, without the bot: real model, real browser,
// real web. Telegram is faked; the database and the photos live in this worktree's scratch
// folder. The bot's database is only read, once, for its non-secret search settings, so the
// search runs with the same models, budgets and limits as in real use.
// Usage: php whole-search.php "<request text>" [--no-icecat]

use App\Jobs\ProcessTelegramMessage;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiSettings;
use App\Services\Products\RequiredSpecifications;
use App\Services\ProductSearch\Codes\CodeDatabases;
use App\Services\ProductSearch\SearchJournal;
use App\Services\Telegram\RequestUsageFootnote;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$root = dirname(__DIR__, 4);
$text = trim((string) ($argv[1] ?? ''));
if ($text === '') {
    exit("usage: php whole-search.php \"<request text>\" [--no-icecat]\n");
}
$scratch = $root.'/storage/app/agent';
if (! is_dir($scratch)) {
    mkdir($scratch, 0777, true);
}
$dbFile = $scratch.'/search.sqlite';
if (! is_file($dbFile)) {
    touch($dbFile);
}
foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'APP_ENV' => 'local'] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (realpath((string) DB::connection()->getDatabaseName()) !== realpath($dbFile)) {
    exit("refusing: not the scratch database.\n");
}
set_time_limit(0);
config(['services.telegram.bot_token' => 'test-token', 'services.telegram.allowed_user_ids' => ['12345'],
    'filesystems.disks.public.root' => $scratch.'/search-storage']);
Artisan::call('migrate', ['--force' => true]);
if (! User::query()->exists()) {
    User::query()->create(['name' => config('services.admin.name') ?: 'admin', 'email' => 'fixture@example.test', 'password' => 'x', 'is_admin' => true]);
}

// The bot's own search settings, keys left out. The bot folder is the main worktree.
$list = (string) shell_exec('git -C '.escapeshellarg($root).' worktree list --porcelain');
$bot = preg_match('/^worktree (.+)$/m', $list, $match) === 1 ? trim($match[1]) : null;
$botDatabase = $bot !== null && realpath($bot) !== realpath($root) ? $bot.'/database/database.sqlite' : null;
if ($botDatabase !== null && is_file($botDatabase)) {
    $live = new PDO('sqlite:'.$botDatabase, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    foreach ($live->query("select key, value from app_settings where key like 'ai.%' and key not like 'ai.keys.%' and key <> 'ai.openai_api_key'") as $row) {
        AppSetting::put($row['key'], $row['value']);
    }
    $live = null;
    echo "search settings: the bot's own\n";
} else {
    echo "search settings: defaults (the bot's database was not found)\n";
}
if (in_array('--no-icecat', $argv, true)) {
    AppSetting::put('services.icecat.enabled', '0');
}

$told = [];
Http::fake([
    'https://api.telegram.org/*' => function ($request) use (&$told) {
        $method = basename((string) parse_url($request->url(), PHP_URL_PATH));
        $said = (string) ($request->data()['text'] ?? ($request->data()['caption'] ?? ''));
        if ($said !== '' && ! in_array($method, ['editMessageText', 'answerCallbackQuery'], true)) {
            $told[] = "[{$method}] ".mb_substr($said, 0, 1800);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => random_int(1000, 99999)]]);
    },
]);
$started = microtime(true);
$updateNo = 980000 + TelegramUpdate::query()->count();
echo 'icecat: '.(CodeDatabases::enabled('icecat') ? 'on' : 'off').', assistant model: '.app(AiSettings::class)->modelFor('server_assistant')."\n";
$update = TelegramUpdate::query()->create(['update_id' => $updateNo, 'telegram_user_id' => '12345', 'chat_id' => '98765', 'message_id' => $updateNo,
    'text' => $text, 'payload' => [], 'status' => 'received']);
$error = null;
try {
    (new ProcessTelegramMessage($update->id, true))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
} catch (Throwable $exception) {
    $error = get_class($exception).': '.mb_substr($exception->getMessage(), 0, 600);
}
$fresh = $update->fresh();
echo "\n=== request #{$update->id} «".mb_substr((string) $fresh->text, 0, 200)."» status {$fresh->status}, ".round((microtime(true) - $started) / 60, 1).' min'
    .($error ? "\nERROR: {$error}" : '')."\n";
echo trim(strip_tags((string) app(RequestUsageFootnote::class)->for($update->id, includeSearchDuration: true)))."\n";
foreach (ProductDraft::query()->where('telegram_update_id', $update->id)->get() as $draft) {
    echo "\ndraft #{$draft->id} [{$draft->status}] {$draft->title}\n  model: {$draft->model} · category: {$draft->category} · gallery: {$draft->gallery_status} · stop: "
        .($draft->gallery_search_stop_reason ?? '-')."\n";
    echo '  required and not found: '.json_encode(array_column(RequiredSpecifications::forDraft($draft), 'key'))."\n";
    foreach ((array) $draft->specifications as $row) {
        echo '  · '.($row['key'] ?? '?').' = '.mb_substr((string) ($row['value'] ?? ''), 0, 80)."\n";
    }
    foreach ($draft->media as $media) {
        echo "  photo {$media->width}x{$media->height} {$media->verification_status} ".$scratch.'/search-storage/'.$media->path."\n     from {$media->source_url}\n     "
            .mb_substr((string) $media->verification_notes, 0, 170)."\n";
    }
}
echo "\n--- what the operator would have been told ---\n";
foreach ($told as $message) {
    echo '  '.preg_replace('/\s+/u', ' ', mb_substr($message, 0, 500))."\n";
}
echo "\n--- journal ---\n";
foreach (array_slice(SearchJournal::timeline($update->id, 400), -70) as $line) {
    echo '  '.mb_substr((string) $line, 0, 200)."\n";
}
