<?php

namespace App\Ai\Tools;

use App\Ai\Agents\ProductGalleryVisionAgent;
use App\Models\AiRun;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class InspectGalleryImages implements Tool
{
    /** @param array<int, string> $allowedImageUrls */
    public function __construct(
        private readonly array $allowedImageUrls,
        private readonly ?int $telegramUpdateId = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Visually inspect up to four image URLs already present in the supplied page/gallery evidence. '
            .'Use only when pixels are needed to understand whether a candidate container is one coherent product '
            .'gallery, whether the product remains visible in effects/lifestyle/unusual-angle frames, or whether '
            .'prominent in-image text uses a language other than English/Czech. This is observational: it never '
            .'selects, rejects, ranks, or proves an exact SKU. Call again with another observed batch if needed.';
    }

    public function handle(Request $request): Stringable|string
    {
        $allowed = collect($this->allowedImageUrls)
            ->filter(fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->mapWithKeys(fn (string $url): array => [ProductImageStorage::normalizeCandidateUrl($url) => $url]);
        $requested = collect($request->array('image_urls'))
            ->filter(fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->map(fn (string $url): string => ProductImageStorage::normalizeCandidateUrl($url))
            ->unique()
            ->take(4)
            ->values();

        if ($requested->isEmpty()) {
            return $this->json(['ok' => false, 'error' => 'No valid image URLs were supplied.']);
        }

        $outsideEvidence = $requested->reject(fn (string $url): bool => $allowed->has($url));
        if ($outsideEvidence->isNotEmpty()) {
            return $this->json([
                'ok' => false,
                'error' => 'Every image URL must come from the current sanitized page or previous gallery observation.',
            ]);
        }

        $resolver = app(ProductImageResolver::class);
        $attachments = [];
        $inspectedUrls = [];
        $downloadErrors = [];

        foreach ($requested as $normalizedUrl) {
            $url = (string) $allowed->get($normalizedUrl);
            $failureReason = null;
            $download = $resolver->download($url, failureReason: $failureReason);

            if (! is_array($download)) {
                $downloadErrors[] = ['url' => $url, 'reason' => $failureReason ?? 'download_failed'];
                continue;
            }

            $attachments[] = Image::fromBase64(
                base64_encode($download['bytes']),
                $download['mime_type'],
            )->as('gallery-observation-'.(count($attachments) + 1))
                ->withProviderOptions(['detail' => (string) config('product-images.gallery_agent_vision_detail', 'high')]);
            $inspectedUrls[] = $url;
        }

        if ($attachments === []) {
            return $this->json(['ok' => false, 'error' => 'Observed images could not be downloaded.', 'downloads' => $downloadErrors]);
        }

        $settings = app(AiSettings::class);
        $timeBudget = app(ProductSearchTimeBudget::class);
        $provider = $settings->providerFor('product_image_vision');
        $model = $settings->modelFor('product_image_vision');
        $timeout = $timeBudget->timeoutFor($this->telegramUpdateId, $settings->imageVisionTimeoutSeconds());
        $productContext = mb_substr(trim((string) $request->string('product_context')), 0, 1000);
        $prompt = json_encode([
            'requested_product_context' => $productContext,
            'inspection_scope' => 'visual observations only; exact SKU must be proven from page evidence',
            'image_urls_in_attachment_order' => $inspectedUrls,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $run = AiRun::query()->create([
            'telegram_update_id' => $this->telegramUpdateId,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $prompt,
            'started_at' => now(),
        ]);

        try {
            $response = app(OpenAiHeavyOperationGate::class)->run(
                $provider,
                $timeout,
                fn () => ProductGalleryVisionAgent::make()->prompt(
                    $prompt,
                    attachments: $attachments,
                    provider: $provider,
                    model: $model,
                    timeout: $timeout,
                ),
            );
            $result = $response->toArray();
            $run->update([
                'invocation_id' => $response->invocationId,
                'status' => 'completed',
                'response' => $result,
                'usage' => $response->usage->toArray(),
                'completed_at' => now(),
            ]);

            return $this->json([
                'ok' => true,
                'inspected_urls' => $inspectedUrls,
                'download_errors' => $downloadErrors,
                'observation' => $result,
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);
            report($exception);

            return $this->json([
                'ok' => false,
                'error' => 'Vision inspection was unavailable; keep the gallery decision uncertain and use other evidence.',
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'image_urls' => $schema->array()->max(4)->items($schema->string()->max(2048))->required()
                ->description('One to four exact URLs already visible in image_candidates/network samples/previous observation.'),
            'product_context' => $schema->string()->max(1000)->required()
                ->description('Requested product plus exact page title/SKU/color evidence. Vision treats this as context, not proof.'),
        ];
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
