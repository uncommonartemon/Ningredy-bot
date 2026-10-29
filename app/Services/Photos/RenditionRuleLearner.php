<?php

namespace App\Services\Photos;

use App\Ai\Agents\RenditionRuleAgent;
use App\Models\AiRun;
use App\Models\ImageRenditionRule;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductSourceAttemptRecorder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * When a site's thumbnails fit no rendition rule, the agent learns the site's own
 * (operator, 2026-10-27: "if a site changes something, can the agent change the rule
 * itself?"). It proposes addresses and measures them; the server keeps the rule only
 * after measuring it again on this site's frames, and once a learned rule keeps
 * missing it stops (RenditionRules::record) and the site is learned again.
 */
final class RenditionRuleLearner
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly ProductSearchCostBudget $costBudget,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductImageResolver $resolver,
        private readonly ProductSourceAttemptRecorder $attempts,
    ) {}

    /**
     * @param  list<array{url: string, width: int, height: int}>  $thumbnails  frames of one image host dropped as too small
     */
    public function learn(string $host, array $thumbnails, int $minimumWidth, ?string $pageUrl, ?int $updateId, ?int $draftId = null): ?ImageRenditionRule
    {
        $host = strtolower($host);
        if ($host === '' || count($thumbnails) < 2 || ! config('product-images.rendition_probe.learning', true)
            || ($updateId && ($this->costBudget->exceeded($updateId) || ! $this->timeBudget->canStart($updateId, 60)))
            // One lesson per site a day: a site the agent could not read today is not paid for again and again.
            || ! Cache::add('rendition-rule-learning:'.$host, true, now()->addDay())) {
            return null;
        }
        $thumbnails = array_slice(array_values($thumbnails), 0, 8);
        $prompt = json_encode([
            'image_host' => $host, 'page_url' => $pageUrl, 'minimum_width' => $minimumWidth, 'thumbnails' => $thumbnails,
            'rules_already_tried_here' => array_map(fn (array $rule): array => ['find' => $rule['find'], 'replace' => $rule['replace']],
                RenditionRules::forHost($host)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        $provider = $this->settings->providerFor('rendition_rule_learning');
        $model = $this->settings->modelFor('rendition_rule_learning');
        $timeout = $this->timeBudget->timeoutFor($updateId, (int) config('services.rendition_rule_learning.timeout', 90));
        $run = AiRun::query()->create([
            'telegram_update_id' => $updateId, 'provider' => $provider, 'model' => $model, 'status' => 'running',
            'prompt' => $prompt, 'started_at' => now(),
            'activity' => ['stage' => 'rendition_rule_learning', 'search_budget' => true, 'product_draft_id' => $draftId, 'host' => $host],
        ]);
        try {
            $response = app(OpenAiHeavyOperationGate::class)->run($provider, $timeout,
                fn () => RenditionRuleAgent::make(refererUrl: $pageUrl)->prompt($prompt, provider: $provider, model: $model, timeout: $timeout));
            $run->update(['invocation_id' => $response->invocationId, 'response' => $response->toArray(),
                'usage' => $response->usage->toArray(), 'status' => 'completed', 'completed_at' => now()]);
            $answer = $response->toArray();
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);
            report($exception);
            $this->journal($host, $pageUrl, $updateId, $draftId, 'agent_unavailable', ['error' => mb_substr($exception->getMessage(), 0, 300)]);

            return null;
        }

        $proposal = ['find' => (string) ($answer['find'] ?? ''), 'flags' => (string) ($answer['flags'] ?? ''), 'replace' => (string) ($answer['replace'] ?? '')];
        $verified = ($answer['decision'] ?? null) === 'rule' && RenditionRules::valid($proposal['find'], $proposal['flags'])
            ? $this->verify($proposal, $thumbnails, $minimumWidth, $pageUrl) : [];
        // Two frames prove a rule - or every frame it applies to, when it applies to
        // fewer (live #328 sintsec.ru: one product thumbnail beside an icon, the
        // agent measured the rule, and "two" threw away the only photo there was).
        $applicable = count(array_filter($thumbnails, function (array $thumbnail) use ($proposal): bool {
            $path = (string) parse_url((string) $thumbnail['url'], PHP_URL_PATH);
            $rewritten = RenditionRules::apply($proposal, $path);

            return $rewritten !== null && $rewritten !== $path;
        }));
        $rule = count(array_unique($verified)) >= min(2, max(1, $applicable))
            ? RenditionRules::learn($host, $proposal['find'], $proposal['flags'], $proposal['replace'], (string) ($answer['reason'] ?? ''),
                (string) array_key_first($verified), (string) $verified[array_key_first($verified)])
            : null;
        $this->journal($host, $pageUrl, $updateId, $draftId, $rule ? 'rule_learned' : 'no_rule',
            ['proposal' => $proposal, 'agent_decision' => $answer['decision'] ?? null, 'reason' => mb_substr((string) ($answer['reason'] ?? ''), 0, 600),
                'verified' => count($verified), 'rule_id' => $rule?->id, 'examples' => array_slice($verified, 0, 3, true)]);

        return $rule;
    }

    /**
     * The server's own measurement: the rule on each thumbnail's path, downloaded and measured.
     *
     * @return array<string, string> thumbnail => the larger address it gave; two frames giving one address count once
     */
    private function verify(array $rule, array $thumbnails, int $minimumWidth, ?string $pageUrl): array
    {
        $guesses = [];
        foreach (array_slice($thumbnails, 0, 6) as $thumbnail) {
            $parts = parse_url((string) $thumbnail['url']);
            if (! is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
                continue;
            }
            $path = RenditionRules::apply($rule, $parts['path']);
            if ($path !== null && $path !== $parts['path']) {
                $guesses[(string) $thumbnail['url']] = ($parts['scheme'] ?? 'https').'://'.$parts['host']
                    .(isset($parts['port']) ? ':'.$parts['port'] : '').$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
            }
        }
        if ($guesses === []) {
            return [];
        }
        $downloads = $this->resolver->downloadMany(array_values($guesses), $pageUrl ? array_fill_keys(array_values($guesses), $pageUrl) : []);
        $widths = array_column($thumbnails, 'width', 'url');

        return array_filter($guesses, function (string $guess, string $thumbnail) use ($downloads, $minimumWidth, $widths): bool {
            $width = (int) ($downloads[$guess]['download']['width'] ?? 0);

            return $width >= $minimumWidth && $width > (int) ($widths[$thumbnail] ?? 0);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** A note, not a failed download: a site without a rule is still a source with its own frames. */
    private function journal(string $host, ?string $pageUrl, ?int $updateId, ?int $draftId, string $decision, array $output): void
    {
        $this->attempts->record([
            'telegram_update_id' => $updateId, 'product_draft_id' => $draftId, 'product_url' => (string) ($pageUrl ?? ''),
            'actor' => 'ai', 'phase' => 'image_download', 'action' => 'learn_rendition_rule', 'status' => 'info',
            'decision' => $decision, 'input' => ['image_host' => $host], 'output' => $output,
        ]);
    }
}
