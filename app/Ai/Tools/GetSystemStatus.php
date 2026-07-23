<?php

namespace App\Ai\Tools;

use App\Models\AiRun;
use App\Services\Ai\AiUsageReporter;
use App\Models\AppSetting;
use App\Models\Product;
use App\Models\ProductDraft;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetSystemStatus implements Tool
{
    public function description(): Stringable|string
    {
        return 'Get concise server, queue, catalog, pending drafts, current public/ngrok URL and recent AI error status. Use this when the user asks for the current proxy, site, public or ngrok address.';
    }

    public function handle(Request $request): Stringable|string
    {
        $publicUrl = rtrim((string) AppSetting::valueFor(
            AppSetting::TELEGRAM_PROXY_URL,
            (string) config('services.telegram.proxy_url'),
        ), '/');

        return json_encode([
            'database' => 'ok',
            'public_url' => $publicUrl !== '' ? $publicUrl : null,
            'catalog_url' => $publicUrl !== '' ? $publicUrl.'/catalog' : null,
            'admin_url' => $publicUrl !== '' ? $publicUrl.'/admin' : null,
            'telegram_webhook_url' => $publicUrl !== '' ? $publicUrl.'/api/telegram/webhook' : null,
            'catalog' => [
                'total' => Product::query()->count(),
                'active' => Product::query()->visibleInCatalog()->count(),
                'inactive' => Product::query()->where('is_active', false)->count(),
            ],
            'pending_drafts' => ProductDraft::query()->where('status', 'pending_review')->count(),
            'queue' => [
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
            ],
            'ai_failures_last_24h' => AiRun::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            'ai_usage' => app(AiUsageReporter::class)->summary(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
