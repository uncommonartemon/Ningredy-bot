<?php

namespace App\Ai\Tools;

use App\Ai\Agents\ProductGalleryVisionAgent;
use App\Models\AiRun;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductImageEncoder;
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
    /**
     * What the vision provider actually accepts. A URL's own extension is
     * never proof of what the server sent - real case: a Lenovo product
     * page linked *.png assets that now serve AVIF, and the raw bytes went
     * to Vision unconverted six rounds running, each one failing the exact
     * same "not a valid image" way while the agent was only ever told
     * "Vision inspection was unavailable".
     */
    private const array VISION_ACCEPTED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** @param array<int, string> $allowedImageUrls */
    public function __construct(
        private readonly array $allowedImageUrls,
        private readonly ?int $telegramUpdateId = null,
        private readonly ?string $originalOperatorRequest = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Visually inspect up to four image URLs already present in the supplied page/gallery evidence. '
            .'Use only when pixels are needed to understand whether a candidate container is one coherent product '
            .'gallery, whether the product remains visible in effects/lifestyle/unusual-angle frames, or whether '
            .'prominent in-image text uses a language other than English/Czech. This is observational: it never '
            .'selects, rejects, ranks, or proves an exact SKU. For an explicitly requested color, inspect several '
            .'informative views from the same candidate slider together; unclear detail views are not conflicts. '
            .'Call again with another observed batch if needed. A URL reported with retryable=false could not be '
            .'converted into a format this tool accepts - never resubmit that exact URL unchanged; a different '
            .'rendition of the same frame may still work.';
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
                $downloadErrors[] = [
                    'url' => $url,
                    'reason' => $failureReason ?? 'download_failed',
                    'retryable' => true,
                ];

                continue;
            }

            $prepared = $this->prepareForVision($download);

            if ($prepared === null) {
                $downloadErrors[] = [
                    'url' => $url,
                    'reason' => 'unsupported_image_format',
                    'observed_format' => $download['mime_type'] ?? 'unknown',
                    'retryable' => false,
                    'note' => 'This exact URL could not be converted into a format Vision accepts - '
                        .'do not resubmit it unchanged. A different rendition/URL for the same frame may work.',
                ];

                continue;
            }

            $attachments[] = Image::fromBase64(
                base64_encode($prepared['bytes']),
                $prepared['mime_type'],
            )->as('gallery-observation-'.(count($attachments) + 1))
                ->withProviderOptions(['detail' => (string) config('product-images.gallery_agent_vision_detail', 'high')]);
            $inspectedUrls[] = $url;
        }

        if ($attachments === []) {
            return $this->json([
                'ok' => false,
                'error' => 'None of the requested URLs produced an image Vision could inspect - see downloads for '
                    .'the reason per URL and whether resubmitting it can help.',
                'downloads' => $downloadErrors,
            ]);
        }

        $settings = app(AiSettings::class);
        $timeBudget = app(ProductSearchTimeBudget::class);
        $provider = $settings->providerFor('product_image_vision');
        $model = $settings->modelFor('product_image_vision');
        $timeout = $timeBudget->timeoutFor($this->telegramUpdateId, $settings->imageVisionTimeoutSeconds());
        $productContext = mb_substr(trim((string) $request->string('product_context')), 0, 1000);
        $prompt = json_encode([
            'original_operator_request' => $this->originalOperatorRequest,
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

            // The full exception message is deliberately not echoed back -
            // it can carry provider/request detail that does not belong in a
            // tool result. Only a known, safe classification is: whether the
            // provider still rejected a prepared image as not a valid image
            // (residual - prepareForVision() already converts every format
            // it can) versus a genuine transient failure worth retrying.
            $imageRejected = str_contains(mb_strtolower($exception->getMessage()), 'valid image');

            return $this->json([
                'ok' => false,
                'error' => $imageRejected
                    ? 'The vision provider still rejected a prepared image as invalid. Do not resubmit these '
                        .'exact URLs unchanged; a different rendition may work.'
                    : 'Vision inspection was unavailable; keep the gallery decision uncertain and use other evidence.',
                'retryable' => ! $imageRejected,
                'inspected_urls' => $inspectedUrls,
            ]);
        }
    }

    /**
     * The vision provider's own accepted format list, not what a URL's
     * extension claims. A candidate is re-encoded through GD when its actual
     * bytes are something else (a *.png link that now serves AVIF is a real
     * production case, not a hypothetical) rather than handed over as-is and
     * left to fail identically every round.
     *
     * @param  array{bytes: string, mime_type?: string, width?: int, height?: int}  $download
     * @return array{bytes: string, mime_type: string}|null
     */
    private function prepareForVision(array $download): ?array
    {
        $mimeType = (string) ($download['mime_type'] ?? '');

        if (in_array($mimeType, self::VISION_ACCEPTED_MIME_TYPES, true)) {
            return ['bytes' => $download['bytes'], 'mime_type' => $mimeType];
        }

        $encoder = app(ProductImageEncoder::class);
        $width = (int) ($download['width'] ?? 0);
        $height = (int) ($download['height'] ?? 0);

        if ($width > 0 && $height > 0 && ! $encoder->isSafeToDecode($width, $height)) {
            return null;
        }

        $decoded = @imagecreatefromstring($download['bytes']);

        if ($decoded === false) {
            return null;
        }

        try {
            $converted = $encoder->toWebp($decoded);

            return ['bytes' => $converted['bytes'], 'mime_type' => 'image/webp'];
        } catch (Throwable) {
            return null;
        } finally {
            imagedestroy($decoded);
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
