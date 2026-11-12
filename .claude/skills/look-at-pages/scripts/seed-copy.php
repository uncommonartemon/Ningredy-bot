<?php

// Makes a fresh scratch database for the agent's own copy of the site and fills it with demo
// data: an admin to sign in with and enough catalog, stock, customers and searches for the
// pages to look lived-in. It refuses to run from the bot folder and never touches its database.
// Usage: php seed-copy.php
// Environment: LOOK_USER (admin login, "admin" by default), LOOK_PASS (password; one is made up
// and printed when it is not given).

use App\Models\AiRun;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Catalog\StockUnits;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 4);
$list = (string) shell_exec('git -C '.escapeshellarg($root).' worktree list --porcelain');
$main = preg_match('/^worktree (.+)$/m', $list, $match) === 1 ? realpath(trim($match[1])) : null;
if ($main !== null && $main === realpath($root)) {
    exit("refusing: this is the bot folder. The copy is made from a linked worktree only.\n");
}
$scratch = $root.'/storage/app/agent';
if (! is_dir($scratch)) {
    mkdir($scratch, 0777, true);
}
$db = $scratch.'/view.sqlite';
if (is_file($db)) {
    unlink($db);
}
touch($db);
$login = getenv('LOOK_USER') ?: 'admin';
$password = getenv('LOOK_PASS') ?: bin2hex(random_bytes(6));
foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'APP_ENV' => 'local', 'OPENAI_API_KEY' => 'none', 'TELEGRAM_BOT_TOKEN' => 'none',
    'ADMIN_NAME' => $login] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (realpath((string) DB::connection()->getDatabaseName()) !== realpath($db)) {
    exit("refusing: not the scratch database.\n");
}
Artisan::call('migrate', ['--force' => true]);
User::query()->create(['name' => $login, 'email' => 'viewer@example.test', 'password' => $password, 'is_admin' => true]);

$wholesale = CustomerGroup::query()->create(['name' => 'Опт', 'note' => 'Демо-группа']);
$service = CustomerGroup::query()->create(['name' => 'Сервисные центры']);
foreach ([['Демо Покупатель', 'buyer@example.test', 'Demo IT s.r.o.', $wholesale], ['Демо Сервис', 'service@example.test', 'Demo Service', $service]] as [$name, $email, $company, $group]) {
    Customer::query()->create(['customer_group_id' => $group->id, 'name' => $name, 'email' => $email, 'company' => $company,
        'password' => $password, 'last_login_at' => now()->subDays(random_int(0, 9))]);
}

$catalog = [
    ['laptops', 'Lenovo', 'Lenovo ThinkPad E14 Gen 5', 'laptop', 18990, 2, 'new'],
    ['laptops', 'HP', 'HP ProBook 450 G10', 'laptop', 21500, 1, 'new'],
    ['laptops', 'MSI', 'MSI Vector 17 HX AI A2XWJG', 'laptop', 78000, 1, 'new'],
    ['ssd', 'Samsung', 'Samsung PM9A1 512 GB M.2 NVMe SSD', 'component', 1290, 3, 'used'],
    ['ssd', 'KIOXIA', 'KIOXIA XG6 512GB M.2 2280 NVMe SSD', 'component', 990, 4, 'used'],
    ['ram', 'Samsung', 'Samsung M393A4K40BB1-CRC0Q 32GB DDR4-2400 RDIMM', 'component', 1450, 6, 'used'],
    ['ram', 'Micron', 'Micron MTA9ASF1G72PZ-2G6J1 8GB DDR4-2666 RDIMM', 'component', 390, 8, 'used'],
    ['cpu', 'Intel', 'Intel Xeon Gold 5416S', 'component', 15900, 1, 'used'],
    ['gpu', 'MSI', 'MSI GeForce RTX 4060 VENTUS 2X BLACK 8G OC', 'component', 7990, 0, 'new'],
];
$suppliers = ['Поставщик А', 'Поставщик Б', 'Поставщик В', 'Поставщик Г'];
$books = app(StockUnits::class);
foreach ($catalog as $index => [$slug, $brandName, $title, $type, $price, $quantity, $condition]) {
    $brand = Brand::query()->firstOrCreate(['name' => $brandName], ['is_active' => true]);
    $product = Product::query()->create(['title' => $title, 'category_id' => Category::query()->where('slug', $slug)->value('id'),
        'brand_id' => $brand->id, 'product_type' => $type, 'status' => 'published', 'is_active' => $index !== 8,
        'published_at' => now()->subDays(20 - $index), 'model' => explode(' ', $title, 2)[1]]);
    $variant = $product->variants()->create(['condition' => $condition, 'price' => $price, 'currency' => 'CZK', 'quantity' => $quantity + 2,
        'stock_status' => $quantity > 0 ? 'in_stock' : 'out_of_stock', 'sku' => strtoupper(substr(md5($title), 0, 8)), 'is_default' => true, 'is_active' => true,
        'group_prices' => [$wholesale->id => round($price * 0.9), $service->id => $index % 2 ? round($price * 0.93) : null]]);
    // Bought over the last months; two of each already sold, so the books have something to show.
    foreach ($variant->stockUnits()->orderBy('id')->get() as $n => $unit) {
        $unit->update(['purchase_price' => round($price * (0.62 + 0.03 * ($n % 4))), 'purchased_at' => now()->subDays(25 * ($n + 1) + $index * 3),
            'supplier' => $suppliers[$n % 4], 'serial_number' => $type === 'component' ? 'S'.strtoupper(substr(md5($title.$n), 0, 14)) : null]);
        if ($n < 2) {
            $books->sell($unit, (string) round($price * (0.96 + 0.02 * $n)), now()->subDays(6 * ($n + 1) + $index * 9));
        }
    }
}

// A few searches of each outcome for the dashboard and the drafts list.
foreach ([['Kingston KVR32S22S8/16', 'pending_review', 'complete', null], ['WD Blue SN570 WDS500G3B0C', 'pending_review', 'partial', null],
    ['Toshiba MQ04ABF100', 'pending_review', 'missing', 'exhausted'], ['ASUS Zenbook 14 UX3405MA', 'approved', 'complete', null]] as $n => [$text, $status, $gallery, $stop]) {
    $update = TelegramUpdate::query()->create(['update_id' => 770000 + $n, 'telegram_user_id' => '1', 'chat_id' => '1', 'text' => $text,
        'payload' => [], 'status' => 'completed', 'processed_at' => now()->subHours($n + 1), 'created_at' => now()->subHours($n + 1)]);
    $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'demo', 'status' => 'completed',
        'prompt' => 'demo', 'started_at' => now()->subHours($n + 1), 'completed_at' => now()->subHours($n + 1)->addMinutes(4),
        'usage' => ['prompt_tokens' => 180000, 'completion_tokens' => 9000]]);
    ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '1',
        'title' => $text, 'product_type' => $n === 3 ? 'laptop' : 'component', 'category' => $n === 3 ? 'laptops' : 'components',
        'status' => $status, 'gallery_status' => $gallery, 'gallery_search_stop_reason' => $stop, 'specifications' => [], 'sources' => [],
        'image_urls' => [], 'images_staged_at' => now()->subHours($n + 1)]);
}
echo 'scratch database: '.$db."\n";
echo 'products '.Product::query()->count().', units '.StockUnit::query()->count().', suppliers '.Supplier::query()->count()
    .', customers '.Customer::query()->count().', drafts '.ProductDraft::query()->count()."\n";
echo "sign-in for the copy: {$login} / {$password}\n";
