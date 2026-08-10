<?php

namespace App\Console\Commands;

use App\Ai\Agents\ProductResearchAgent;
use App\Services\Ai\AiSettings;
use App\Services\Ai\StreamingProductResearch;
use Illuminate\Console\Command;
use Laravel\Ai\Streaming\Events\StreamEvent;

class DiagnoseProductResearch extends Command
{
    protected $signature = 'product:diagnose-research
        {query* : Exact product query}
        {--hard=900 : Absolute request deadline in seconds}
        {--idle=90 : Maximum seconds without streamed network data}';

    protected $description = 'Run product Web Search without creating drafts or sending Telegram messages';

    public function handle(AiSettings $settings, StreamingProductResearch $research): int
    {
        $started = microtime(true);
        $response = $research->prompt(
            ProductResearchAgent::make(),
            implode(' ', (array) $this->argument('query')),
            $settings->providerFor('product_research'),
            $settings->modelFor('product_research'),
            max(1, (int) $this->option('hard')),
            max(1, (int) $this->option('idle')),
            function (StreamEvent $event) use ($started): void {
                $data = $event->toArray();
                $this->line(sprintf(
                    '%6.1fs  %-55s %s',
                    microtime(true) - $started,
                    $event::class,
                    json_encode([
                        'type' => $data['type'] ?? null,
                        'status' => $data['status'] ?? null,
                    ], JSON_UNESCAPED_SLASHES),
                ));
                fflush(STDOUT);
            },
        );
        $result = $response->toArray();

        $this->newLine();
        $this->line(json_encode([
            'elapsed_seconds' => round(microtime(true) - $started, 1),
            'invocation_id' => $response->invocationId,
            'usage' => $response->usage->toArray(),
            'result' => array_intersect_key($result, array_flip([
                'status',
                'title',
                'brand',
                'model',
                'primary_source_url',
                'confidence',
            ])),
            'source_count' => count($result['sources'] ?? []),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
