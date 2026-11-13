<?php

// Sends ONE request to the running bot as the operator's own message, so the answer arrives in
// their Telegram, then watches it to the end and prints what came of it. The bot itself is
// neither started nor stopped. Nothing is sent while the bot is busy.
// Usage: php one-request.php "<request text>"
// Environment: BOT_CHAT_ID and BOT_USER_ID name the chat. Without them the chat of the last
// message the bot received is used.

use App\Models\TelegramUpdate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$text = trim((string) ($argv[1] ?? ''));
if ($text === '') {
    exit("usage: php one-request.php \"<request text>\"\n");
}
$list = (string) shell_exec('git -C '.escapeshellarg(dirname(__DIR__, 4)).' worktree list --porcelain');
$bot = preg_match('/^worktree (.+)$/m', $list, $match) === 1 ? trim($match[1]) : null;
if ($bot === null || ! is_file($bot.'/artisan')) {
    exit("the bot folder was not found\n");
}
chdir($bot);
require $bot.'/vendor/autoload.php';
$app = require $bot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $open = TelegramUpdate::query()->whereIn('status', ['processing', 'received', 'transcribed', 'delivering'])->whereNull('processed_at')->exists();
    if ($open || DB::table('jobs')->count() > 0) {
        exit("the bot is busy with another request: nothing sent\n");
    }
    $last = DB::table('telegram_updates')->whereNotNull('chat_id')->where('update_id', '<', 1900000000)->orderByDesc('id')->first(['chat_id', 'telegram_user_id']);
    $chat = getenv('BOT_CHAT_ID') ?: ($last->chat_id ?? null);
    $user = getenv('BOT_USER_ID') ?: ($last->telegram_user_id ?? $chat);
    if (blank($chat)) {
        exit("no chat to write to: set BOT_CHAT_ID\n");
    }
    $port = parse_url((string) config('app.url'), PHP_URL_PORT) ?: 8000;
    $raw = (string) config('services.telegram.webhook_secret');
    $secret = preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $raw) === 1 ? $raw : hash('sha256', $raw);
    $updateId = 1900000000 + random_int(0, 99999999);
    Http::timeout(60)->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $secret])
        ->post("http://127.0.0.1:{$port}/api/telegram/webhook", ['update_id' => $updateId, 'message' => [
            'message_id' => random_int(900000, 999999), 'from' => ['id' => (int) $user, 'is_bot' => false, 'first_name' => 'Operator'],
            'chat' => ['id' => (int) $chat, 'type' => 'private'], 'date' => time(), 'text' => $text]])->throw();
    $row = TelegramUpdate::query()->where('update_id', $updateId)->first();
    if ($row === null) {
        exit("the webhook answered, but no request row appeared\n");
    }
    echo date('H:i:s')." sent as request #{$row->id}: {$text}\n";
    flush();
    $started = microtime(true);
    $lastStage = '';
    while (microtime(true) - $started < 2100) {
        sleep(20);
        $row->refresh();
        $activity = (string) DB::table('ai_runs')->where('telegram_update_id', $row->id)->orderByDesc('id')->value('activity');
        $stage = (string) (json_decode($activity, true)['stage'] ?? '');
        if ($stage !== $lastStage && $stage !== '') {
            echo date('H:i:s')." stage: {$stage}\n";
            flush();
            $lastStage = $stage;
        }
        $busy = DB::table('jobs')->count() > 0 || DB::table('ai_runs')->where('telegram_update_id', $row->id)->where('status', 'running')->exists();
        if ($row->processed_at !== null && ! $busy) {
            break;
        }
    }
    $draft = DB::table('product_drafts')->where('telegram_update_id', $row->id)->orderByDesc('id')->first();
    $draftPhotos = $draft ? DB::table('product_draft_media')->where('product_draft_id', $draft->id)->count() : 0;
    // A card approved at once has its photos moved to the product.
    $productPhotos = $draft && $draft->approved_product_id ? DB::table('product_media')->where('product_id', $draft->approved_product_id)->count() : 0;
    echo date('H:i:s').' result: status '.$row->status.', '.round((microtime(true) - $started) / 60, 1).' min, model calls '
        .DB::table('ai_runs')->where('telegram_update_id', $row->id)->count()."\n";
    echo $draft
        ? "draft #{$draft->id} «{$draft->title}», state {$draft->status}, gallery ".($draft->gallery_status ?? '-').", photos in the draft {$draftPhotos}"
            .($productPhotos ? ", photos in the product {$productPhotos}" : '')."\n"
        : 'no draft'.(filled($row->error) ? ': '.mb_substr((string) $row->error, 0, 300) : '')."\n";
} catch (Throwable $exception) {
    echo 'error: '.mb_substr($exception->getMessage(), 0, 300)."\n";
}
