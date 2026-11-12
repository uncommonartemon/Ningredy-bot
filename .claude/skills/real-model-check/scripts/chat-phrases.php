<?php

// Runs operator phrases through the real assistant model on a throwaway in-memory database and
// prints, for each phrase, which tools the model called and what it answered.
// Telegram and the code databases are faked, the web research agents are faked, so nothing
// searches the web and nothing reaches a chat. The bot and its database are not touched.
// Usage: php chat-phrases.php <set> [numbers of phrases to run]
// A set is a file in phrases/: it returns a function that gets the fixture and returns the
// phrases, each as [text, what is expected, optional text of the message being replied to].

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ProductTextEvidenceAgent;
use App\Jobs\ProcessTelegramMessage;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\StockUnit;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\InvokingTool;

$root = dirname(__DIR__, 4);
chdir($root);
foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'APP_ENV' => 'local'] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== ':memory:') {
    exit("refusing: not the in-memory database.\n");
}
$name = preg_replace('/[^a-z0-9-]/', '', (string) ($argv[1] ?? ''));
$file = __DIR__.'/phrases/'.$name.'.php';
if ($name === '' || ! is_file($file)) {
    exit('usage: php chat-phrases.php <set> [numbers]; sets: '.implode(', ', array_map(fn (string $path): string => basename($path, '.php'), glob(__DIR__.'/phrases/*.php') ?: []))."\n");
}
config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'x', 'services.icecat.username' => null,
    'services.telegram.allowed_user_ids' => ['12345']]);
echo 'model key configured: '.(filled(config('ai.providers.openai.key')) ? 'yes' : 'no')."\n";
Artisan::call('migrate', ['--force' => true]);
Queue::fake();
Storage::fake('public');
$telegramLog = [];
Http::fake([
    'https://api.telegram.org/*' => function ($request) use (&$telegramLog) {
        $method = basename((string) parse_url($request->url(), PHP_URL_PATH));
        $text = (string) ($request->data()['text'] ?? ($request->data()['caption'] ?? ''));
        $telegramLog[] = $method.($text !== '' ? ': '.mb_substr((string) preg_replace('/\s+/u', ' ', $text), 0, 170) : '');

        return Http::response(['ok' => true, 'result' => ['message_id' => random_int(1000, 9999)]]);
    },
    'https://live.icecat.biz/*' => Http::response(['StatusCode' => 8, 'Message' => 'not present'], 404),
    'https://api.upcitemdb.com/*' => Http::response(['items' => []]),
]);
ProductResearchAgent::fake(fn (): array => ['status' => 'not_found', 'title' => null]);
ProductTextEvidenceAgent::fake(fn (): array => ['summary' => 'Found on a benchmark page.', 'facts' => [
    ['key' => 'write_speed', 'name' => 'Write speed', 'value' => '5000 MB/s', 'group' => null, 'source_url' => 'https://bench.example/ssd',
        'source_excerpt' => 'Sequential write: 5000 MB/s', 'source_type' => 'review', 'scope' => 'exact_configuration', 'applicability' => 'same part number'],
]]);
$tools = [];
Event::listen(InvokingTool::class, function (InvokingTool $event) use (&$tools): void {
    $tools[] = class_basename($event->tool).' '.mb_substr((string) json_encode($event->arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 260);
});

// The fixture every set starts from: two drafts waiting for a decision and one product in the catalog.
User::query()->create(['name' => config('services.admin.name') ?: 'admin', 'email' => 'fixture@example.test', 'password' => 'x', 'is_admin' => true]);
$category = Category::query()->where('slug', 'ssd')->first()
    ?? Category::query()->firstOrCreate(['slug' => 'components'], ['name' => 'Components', 'is_active' => true, 'product_type_affinity' => 'component']);
[$chat, $user, $updateNo] = ['98765', '12345', 960000];
$makeUpdate = function (string $text, string $status = 'received') use (&$updateNo, $chat, $user): TelegramUpdate {
    return TelegramUpdate::query()->create(['update_id' => ++$updateNo, 'chat_id' => $chat, 'telegram_user_id' => $user, 'message_id' => $updateNo,
        'text' => $text, 'payload' => [], 'status' => $status]);
};
$origin = $makeUpdate('Samsung PM991 MZALQ512HALU-000L1 512GB', 'completed');
$run = $origin->aiRuns()->create(['provider' => 'local', 'model' => 'fixture', 'status' => 'completed', 'prompt' => 'Fixture', 'started_at' => now(), 'completed_at' => now()]);
$draft = ProductDraft::query()->create(['telegram_update_id' => $origin->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => $user,
    'title' => 'Samsung PM991 512GB NVMe SSD', 'brand' => 'Samsung', 'model' => 'MZALQ512HALU-000L1', 'description' => 'An OEM NVMe drive.', 'status' => 'pending_review',
    'category' => $category->slug, 'product_type' => 'component', 'condition' => 'new', 'color' => null,
    'specifications' => [['key' => 'mpn', 'name' => 'Part number', 'value' => 'MZALQ512HALU-000L1'], ['key' => 'storage', 'name' => 'Capacity', 'value' => '512 GB'],
        ['key' => 'weight', 'name' => 'Weight', 'value' => '9 g']],
    'sources' => [], 'image_urls' => [], 'primary_source_url' => 'https://shop.example/exact', 'gallery_status' => 'complete', 'images_staged_at' => now()]);
ProductDraft::withReconciliationWrite(fn () => $draft->update(['specifications_reconciled_source_url' => 'https://shop.example/exact']));
Storage::disk('public')->put("drafts/{$draft->id}/1.jpg", 'fake-image');
$draft->media()->create(['disk' => 'public', 'path' => "drafts/{$draft->id}/1.jpg", 'checksum' => hash('sha256', 'fake-image'), 'width' => 1000, 'height' => 800,
    'verification_status' => 'verified', 'is_primary' => true, 'sort_order' => 1]);
$secondOrigin = $makeUpdate('Kingston KVR32S22S8/16', 'completed');
$secondRun = $secondOrigin->aiRuns()->create(['provider' => 'local', 'model' => 'fixture', 'status' => 'completed', 'prompt' => 'Fixture', 'started_at' => now(), 'completed_at' => now()]);
$other = ProductDraft::query()->create(['telegram_update_id' => $secondOrigin->id, 'ai_run_id' => $secondRun->id, 'requested_by_telegram_user_id' => $user,
    'title' => 'Kingston ValueRAM 16GB DDR4', 'brand' => 'Kingston', 'model' => 'KVR32S22S8/16', 'description' => 'Memory.', 'status' => 'pending_review',
    'category' => $category->slug, 'product_type' => 'component', 'condition' => 'new', 'specifications' => [], 'sources' => [], 'image_urls' => [], 'gallery_status' => 'partial']);
$product = Product::query()->create(['category_id' => $category->id, 'title' => 'Lenovo Test Laptop 14', 'model' => 'TEST-14', 'description' => 'A laptop.',
    'product_type' => 'component', 'status' => 'published', 'is_active' => true, 'translations' => ['uk' => ['title' => 'Стара назва', 'description' => 'Старий опис']]]);
$variant = ProductVariant::query()->create(['product_id' => $product->id, 'fingerprint' => 'fixture-1', 'name' => 'Base', 'is_default' => true, 'is_active' => true,
    'currency' => 'CZK', 'stock_status' => 'unknown', 'condition' => 'new', 'sku' => 'TEST-14-A']);
$frequency = AttributeDefinition::query()->firstOrCreate(['key' => 'boost_clock'], ['label' => 'Boost clock', 'data_type' => 'text', 'is_variant' => false]);
AttributeValue::query()->create(['attribute_definition_id' => $frequency->id, 'product_id' => $product->id, 'value' => '2505 MHz']);
$weight = AttributeDefinition::query()->firstOrCreate(['key' => 'weight_note'], ['label' => 'Weight note', 'data_type' => 'text', 'is_variant' => false]);
AttributeValue::query()->create(['attribute_definition_id' => $weight->id, 'product_id' => $product->id, 'value' => 'Heavy']);

$scenarios = (require $file)(compact('draft', 'other', 'product', 'variant', 'origin', 'secondOrigin'));
$only = array_values(array_filter(array_slice($argv, 2), 'is_numeric'));
[$totalIn, $totalOut] = [0, 0];
foreach ($scenarios as $index => $scenario) {
    [$text, $expected] = $scenario;
    if ($only !== [] && ! in_array((string) ($index + 1), $only, true)) {
        continue;
    }
    $tools = [];
    $telegramLog = [];
    $update = $makeUpdate($text);
    if (isset($scenario[2])) {
        $update->update(['reply_to_text' => $scenario[2]]);
    }
    $started = microtime(true);
    $error = null;
    try {
        (new ProcessTelegramMessage($update->id, true))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
    } catch (Throwable $exception) {
        $error = get_class($exception).': '.mb_substr($exception->getMessage(), 0, 300);
    }
    $runRow = AiRun::query()->where('telegram_update_id', $update->id)->where('activity->stage', 'assistant')->latest('id')->first();
    $usage = (array) ($runRow?->usage ?? []);
    $totalIn += (int) ($usage['prompt_tokens'] ?? 0);
    $totalOut += (int) ($usage['completion_tokens'] ?? 0);
    $response = (array) ($runRow?->response ?? []);
    echo "\n".($index + 1).". «{$text}»\n   expected: {$expected}\n";
    echo '   tools: '.($tools === [] ? '(none)' : "\n      ".implode("\n      ", $tools))."\n";
    echo '   answer: ['.($response['response_type'] ?? '-').'] '.mb_substr((string) preg_replace('/\s+/u', ' ', (string) ($response['message'] ?? '')), 0, 300)."\n";
    echo '   telegram: '.mb_substr(implode(' | ', array_slice($telegramLog, 0, 5)), 0, 460)."\n";
    $failure = $update->fresh()->error;
    echo '   '.round(microtime(true) - $started, 1).' s, status '.$update->fresh()->status.($error ? ", ERROR: {$error}" : '')
        .($failure ? ', error: '.mb_substr((string) $failure, 0, 900) : '')."\n";
}

echo "\n=== what the database holds at the end ===\n";
$fresh = $draft->fresh();
echo "draft {$draft->id}: reconciliation ".($fresh->reconciliationPending() ? 'LOST' : 'kept').', colour '.($fresh->color ?? '-')."\n";
foreach ((array) $fresh->specifications as $row) {
    echo '  '.($row['key'] ?? '?').' = '.($row['value'] ?? '?').(($row['operator_stated'] ?? false) ? ' (stated by the operator)' : '')."\n";
}
$freshProduct = $product->fresh();
$freshVariant = $variant->fresh();
echo "product {$product->id}: ".($freshProduct ? $freshProduct->title.', on sale: '.($freshProduct->isOnSale() ? 'yes' : 'no') : 'deleted')."\n";
if ($freshVariant) {
    echo "  price {$freshVariant->price} {$freshVariant->currency}, quantity {$freshVariant->quantity}, mpn {$freshVariant->mpn}, gtin {$freshVariant->gtin}, condition {$freshVariant->condition}\n";
}
foreach (StockUnit::query()->orderBy('id')->get() as $unit) {
    echo "  {$unit->code} {$unit->status} bought ".($unit->purchase_price ?? '-').' '.($unit->purchased_at?->toDateString() ?? '-').' from '.($unit->supplier ?? '-')
        .' sold '.($unit->sold_price ?? '-').' '.($unit->sold_at?->toDateString() ?? '-')."\n";
}
foreach (CustomerGroup::query()->get() as $group) {
    echo "  customer group {$group->name}, invitation code {$group->invite_code}\n";
}
foreach (AttributeValue::query()->with('definition')->where('product_id', $product->id)->get() as $value) {
    echo '  '.$value->definition->key.' = '.$value->value."\n";
}
echo "assistant tokens: in {$totalIn}, out {$totalOut}\n";
