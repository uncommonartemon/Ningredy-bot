<?php

namespace App\Services\Playwright;

use App\Services\Ai\AiSettings;
use App\Services\Products\BrowserGalleryCheckpoint;
use App\Services\Products\BrowserProductImageTransferStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/** Runs one process and owns its temporary files. Never trains or activates recipes. */
class PlaywrightRunner implements BrowserRunner
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly BrowserProductImageTransferStore $transfers,
    ) {}

    public function run(BrowserRunRequest $request, ?callable $debug = null): BrowserRunResult
    {
        $directory = storage_path('framework/product-gallery-browser/'.Str::uuid());

        try {
            File::ensureDirectoryExists($directory);
            $process = $this->process($request, $directory);
            $process->run();

            if (! $process->isSuccessful()) {
                $error = mb_substr(trim($process->getErrorOutput()), 0, 1000);
                $debug?->__invoke('error', "Playwright упал: {$error}");
                Log::debug('Browser product gallery extraction failed.', [
                    'host' => parse_url($request->url, PHP_URL_HOST), 'error' => $error,
                ]);

                return new BrowserRunResult(['images' => [], 'error' => $error, 'failure_kind' => 'browser_process'], $error);
            }

            $result = json_decode(trim($process->getOutput()), true);
            if (! is_array($result)) {
                return new BrowserRunResult(['images' => [], 'error' => 'Playwright returned invalid JSON.', 'failure_kind' => 'browser_protocol'], $process->getErrorOutput());
            }

            $this->transfers->remember($result['transferred_images'] ?? [], $directory);
            unset($result['transferred_images']);
            // Copy while the process directory still exists. No file path escapes its lifetime.
            $screenshot = is_string($result['screenshot_path'] ?? null) && is_file($result['screenshot_path'])
                ? @file_get_contents($result['screenshot_path']) : false;
            $result['screenshot'] = is_string($screenshot) && $screenshot !== '' ? $screenshot : null;
            unset($result['screenshot_path']);
            $result['images'] = collect($result['images'] ?? [])
                ->filter(fn (mixed $image): bool => is_string($image)
                    && filter_var($image, FILTER_VALIDATE_URL) !== false
                    && in_array(parse_url($image, PHP_URL_SCHEME), ['http', 'https'], true))
                ->unique()
                ->when($request->scoutOnly || $request->recipe === [], fn ($urls) => $urls->take($request->limit))
                ->values()->all();

            return new BrowserRunResult($result, $process->getErrorOutput(), hasObservation: true);
        } catch (ProcessTimedOutException $exception) {
            $recovered = BrowserGalleryCheckpoint::interrupted($directory, $exception->getMessage());
            $debug?->__invoke('warning', 'Playwright превысил лимит времени; источник можно повторить позже.');
            Log::notice('Browser product gallery extraction timed out.', [
                'host' => parse_url($request->url, PHP_URL_HOST), 'error' => $exception->getMessage(),
            ]);

            return new BrowserRunResult($recovered);
        } catch (Throwable $exception) {
            $debug?->__invoke('error', 'Playwright: '.$exception->getMessage());
            Log::debug('Browser product gallery extractor was unavailable.', [
                'host' => parse_url($request->url, PHP_URL_HOST), 'error' => $exception->getMessage(),
            ]);

            return new BrowserRunResult(['images' => [], 'error' => $exception->getMessage(), 'failure_kind' => 'browser_unavailable']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function process(BrowserRunRequest $request, string $directory): Process
    {
        $process = new Process([
            (string) config('product-images.browser_fallback.node_binary', 'node'),
            base_path((string) config('product-images.browser_fallback.script', 'scripts/extract-product-gallery.mjs')),
            $request->url,
            (string) min(60, $request->limit),
        ], base_path(), [
            'PRODUCT_GALLERY_RECIPE' => json_encode($request->recipe, JSON_UNESCAPED_SLASHES),
            'PRODUCT_GALLERY_SCOUT_ONLY' => $request->scoutOnly ? '1' : '0',
            'PRODUCT_GALLERY_OBSERVATION_FOCUS' => (string) ($request->context['observation_focus_selector'] ?? ''),
            'PRODUCT_GALLERY_REQUESTED_COLOR' => mb_substr((string) ($request->context['requested_color'] ?? ''), 0, 80),
            'PRODUCT_GALLERY_CONFINE_HOST' => $request->confineNavigationToHost ?? '',
            'PRODUCT_GALLERY_DOM_WAIT_MS' => (string) config('product-images.browser_fallback.dom_wait_ms', 12000),
            'PRODUCT_GALLERY_PROBE_TIMEOUT_MS' => (string) config('product-images.browser_fallback.image_probe_timeout_ms', 5000),
            'PRODUCT_GALLERY_MINIMUM_WIDTH' => (string) max(100, min(4000, (int) (
                $request->context['minimum_image_width'] ?? $this->settings->imageMinimumWidth()
            ))),
            'PRODUCT_GALLERY_MINIMUM_HEIGHT' => (string) max(0, min(4000, (int) (
                $request->context['minimum_image_height'] ?? $this->settings->imageMinimumHeight()
            ))),
            // Leave the JS process time to serialize its partial checkpoint before termination.
            'PRODUCT_GALLERY_DEADLINE_MS' => (string) max(10000, ($request->timeoutSeconds - 12) * 1000),
            'PRODUCT_GALLERY_TRANSFER_DIR' => $directory,
            'PRODUCT_IMAGE_DISABLE_HTTP2' => $request->withoutHttp2 ? 'true' : 'false',
            'PRODUCT_IMAGE_BROWSER_HEADLESS' => $this->settings->browserHeadless() ? 'true' : 'false',
        ]);
        $process->setTimeout((float) $request->timeoutSeconds);

        return $process;
    }
}
