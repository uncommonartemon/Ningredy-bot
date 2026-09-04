<?php

namespace App\Console\Commands;

use App\Ai\Agents\GalleryTextLanguageAgent;
use App\Models\ProductGalleryRecipe;
use App\Services\Ai\AiSettings;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductImageResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Image;
use Throwable;

/**
 * What the test suite cannot see.
 *
 * Every bug found on 2026-09-04 - a missing media type that answered 400 on
 * every training round, a shared browser that hung each extraction, a recipe
 * field dropped between PHP and the browser - passed the whole suite. The suite
 * proves the code agrees with itself: the browser is a mock, the provider is a
 * fake, the queue never runs. This command proves the opposite, by doing the
 * real thing once: a real Chromium against a real shop, a real download, a real
 * (small) call to the provider with a real image attached.
 *
 * Nothing here is hardcoded to a shop. The target is the recipe the bot is
 * actually best at, read from the database, so the check follows the catalog
 * rather than a URL frozen into the source.
 */
class SmokeCheck extends Command
{
    protected $signature = 'bot:smoke
        {--url= : Product page to exercise (default: the most successful active recipe)}
        {--skip-ai : Skip the paid provider call}
        {--skip-browser : Skip everything that needs Chromium}';

    protected $description = 'Exercise the real browser, download and AI paths once, and report honestly';

    /** @var array<int, array{name: string, ok: bool, detail: string}> */
    private array $results = [];

    public function handle(
        BrowserProductGalleryExtractor $browser,
        ProductImageResolver $resolver,
        AiSettings $settings,
    ): int {
        $recipe = $this->targetRecipe();
        $url = (string) ($this->option('url') ?: $this->urlFor($recipe));

        if ($url === '') {
            $this->components->error('No --url given and no trained recipe with a sample path to borrow one from.');

            return self::FAILURE;
        }

        $this->components->info('Target: '.$url);

        $this->check('https certificate chain', function () use ($url): string {
            $response = Http::timeout(30)->withOptions(['allow_redirects' => true])->get($url);

            // Verification is on by default; the point of the check is that a
            // real TLS handshake completes at all. A shop answering 403 has
            // still proved the chain - the refusal came from the server.
            return 'HTTP '.$response->status().($response->status() === 403 ? ' (shop refused us, TLS fine)' : '');
        });

        $images = [];

        if (! $this->option('skip-browser')) {
            $scout = null;

            $this->check('chromium launches and scouts the page', function () use ($browser, $url, &$scout): string {
                $scout = $browser->scout($url);
                $title = (string) data_get($scout, 'scout.title', '');
                $candidates = count((array) data_get($scout, 'scout.image_candidates', []));

                if ($title === '' && $candidates === 0) {
                    throw new \RuntimeException('The browser returned nothing: no title and no image candidates.');
                }

                return $candidates.' image candidates, title "'.mb_substr($title, 0, 60).'"';
            });

            if ($recipe && is_array($recipe->recipe) && $recipe->recipe !== []) {
                $this->check('a stored recipe still collects photographs', function () use ($browser, $recipe, $url, &$images): string {
                    $result = $browser->executeRecipe($url, $this->withAbsentGateProbe($recipe->recipe));
                    $images = array_values(array_filter(
                        (array) ($result['images'] ?? []),
                        fn (mixed $image): bool => is_string($image) && $image !== '',
                    ));
                    $trace = (array) ($result['action_trace'] ?? []);
                    $probeSkipped = collect($trace)->contains(
                        fn (mixed $step): bool => is_array($step) && ($step['optional_absent'] ?? false) === true,
                    );

                    if ($images === []) {
                        throw new \RuntimeException('The recipe executed and collected no images.');
                    }

                    // The probe is an if_present step whose selector cannot
                    // match anything. Seeing it reported absent - rather than
                    // failing the run - is the only proof outside a unit test
                    // that the conditional field survived PHP, survived the JS
                    // normaliser and was understood by the runner.
                    if (! $probeSkipped) {
                        throw new \RuntimeException(
                            'The if_present probe was not reported absent: the conditional step is being dropped '
                            .'somewhere between validation and the browser.',
                        );
                    }

                    return count($images).' images, if_present probe skipped as it should be';
                });
            }
        }

        $downloaded = null;

        if ($images !== []) {
            $this->check('an image downloads and decodes', function () use ($resolver, $images, $url, &$downloaded): string {
                $failure = null;
                $downloaded = $resolver->download($images[0], refererUrl: $url, failureReason: $failure);

                if ($downloaded === null) {
                    throw new \RuntimeException('Download failed: '.($failure ?: 'no reason given'));
                }

                return ($downloaded['width'] ?? 0).'x'.($downloaded['height'] ?? 0)
                    .', '.number_format((int) strlen((string) ($downloaded['bytes'] ?? '')) / 1024, 0).' KB';
            });
        }

        if (! $this->option('skip-ai')) {
            $this->check('the provider accepts a call with an image attached', function () use ($settings, $downloaded): string {
                $provider = $settings->providerFor('product_image_vision');
                $model = $settings->modelFor('product_image_vision');
                $attachments = [];

                if (is_string($downloaded['bytes'] ?? null)) {
                    // The exact shape that answered 400 on every training round
                    // when its media type was missing. A synthetic pixel would
                    // not prove the encoding path; a real downloaded frame does.
                    $attachments[] = Image::fromBase64(
                        base64_encode($downloaded['bytes']),
                        (string) ($downloaded['mime_type'] ?? 'image/jpeg'),
                    )->as('frame-1.'.(explode('/', (string) ($downloaded['mime_type'] ?? 'image/jpeg'))[1] ?? 'jpg'));
                }

                $response = GalleryTextLanguageAgent::make()->prompt(
                    json_encode([
                        'frames' => count($attachments),
                        'question' => 'Smoke check. Answer with an empty foreign_text_frames list unless a frame '
                            .'genuinely carries prominent non-English text.',
                    ], JSON_UNESCAPED_UNICODE) ?: '{}',
                    attachments: $attachments,
                    provider: $provider,
                    model: $model,
                    timeout: 90,
                );
                $answer = $response->toArray();

                if (! array_key_exists('foreign_text_frames', $answer)) {
                    throw new \RuntimeException('The provider answered without the structured field the schema requires.');
                }

                return $provider.'/'.$model.', '.count($attachments).' attachment(s), structured answer received';
            });
        }

        return $this->report();
    }

    private function check(string $name, callable $probe): void
    {
        $started = microtime(true);

        try {
            $detail = (string) $probe();
            $this->results[] = ['name' => $name, 'ok' => true, 'detail' => $detail];
            $this->components->twoColumnDetail(
                '<fg=green>PASS</> '.$name,
                $detail.' <fg=gray>('.round(microtime(true) - $started, 1).'s)</>',
            );
        } catch (Throwable $exception) {
            $this->results[] = ['name' => $name, 'ok' => false, 'detail' => $exception->getMessage()];
            $this->components->twoColumnDetail(
                '<fg=red>FAIL</> '.$name,
                mb_substr($exception->getMessage(), 0, 160).' <fg=gray>('.round(microtime(true) - $started, 1).'s)</>',
            );
        }
    }

    private function report(): int
    {
        $failed = collect($this->results)->reject(fn (array $result): bool => $result['ok']);
        $this->newLine();

        if ($failed->isEmpty()) {
            $this->components->info(count($this->results).' checks passed against the real browser, network and provider.');

            return self::SUCCESS;
        }

        $this->components->error($failed->count().' of '.count($this->results).' checks failed.');

        foreach ($failed as $result) {
            $this->line('  <fg=red>'.$result['name'].'</>: '.$result['detail']);
        }

        return self::FAILURE;
    }

    /**
     * An if_present step whose selector cannot match anything on any page. It
     * must be reported absent and must not fail the recipe.
     *
     * @param  array<string, mixed>  $recipe
     * @return array<string, mixed>
     */
    private function withAbsentGateProbe(array $recipe): array
    {
        $actions = is_array($recipe['actions'] ?? null) ? $recipe['actions'] : [];
        array_unshift($actions, [
            'kind' => 'click',
            'when' => 'if_present',
            'selector' => '.ningredy-smoke-check-gate-that-cannot-exist',
            'index' => 0,
            'limit' => 1,
            'wait_after_ms' => 50,
            'after_each_selector' => null,
            'after_each_limit' => null,
            'after_each_wait_after_ms' => null,
            'purpose' => 'smoke check: a gate that is never up',
        ]);
        $recipe['actions'] = $actions;

        return $recipe;
    }

    private function targetRecipe(): ?ProductGalleryRecipe
    {
        return ProductGalleryRecipe::query()
            ->where('status', 'active')
            ->where('source_blocked', false)
            ->where('success_count', '>', 0)
            ->whereNotNull('sample_path')
            ->orderByDesc('success_count')
            ->orderByDesc('last_success_at')
            ->first();
    }

    private function urlFor(?ProductGalleryRecipe $recipe): string
    {
        if (! $recipe || ! is_string($recipe->sample_path) || $recipe->sample_path === '') {
            return '';
        }

        return 'https://'.$recipe->domain.'/'.ltrim($recipe->sample_path, '/');
    }
}
