<?php

namespace App\Ai\Tools;

use App\Exceptions\ProductSearchCancelled;
use App\Services\Photos\ImageUrls;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductRequestContext;
use App\Services\Vision\VisionCallContext;
use App\Services\Vision\VisionImagePreparer;
use App\Services\Vision\VisionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class InspectGalleryImages implements Tool
{
    /** @param array<int, string> $allowedImageUrls */
    public function __construct(
        private readonly array $allowedImageUrls,
        // Budget and audit key of the current run only.
        private readonly ?int $telegramUpdateId = null,
        private readonly ?ProductRequestContext $productRequest = null,
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
            ->mapWithKeys(fn (string $url): array => [ImageUrls::normalizeCandidateUrl($url) => $url]);
        $requested = collect($request->array('image_urls'))
            ->filter(fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->map(fn (string $url): string => ImageUrls::normalizeCandidateUrl($url))
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
        $images = app(VisionImagePreparer::class);
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

            $prepared = $images->prepare($download);

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

            $attachments[] = $images->attachment(
                $prepared,
                'gallery-observation-'.(count($attachments) + 1),
                (string) config('product-images.gallery_agent_vision_detail', 'high'),
            );
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

        $productContext = mb_substr(trim((string) $request->string('product_context')), 0, 1000);
        $prompt = json_encode([
            ...($this->productRequest ?? ProductRequestContext::unavailable())->forAgent(),
            'requested_product_context' => $productContext,
            'inspection_scope' => 'visual observations only; exact SKU must be proven from page evidence',
            'image_urls_in_attachment_order' => $inspectedUrls,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        try {
            $result = app(VisionService::class)->inspectGallery(
                $prompt,
                $attachments,
                new VisionCallContext($this->telegramUpdateId),
            );

            return $this->json([
                'ok' => true,
                'inspected_urls' => $inspectedUrls,
                'download_errors' => $downloadErrors,
                'observation' => $result,
            ]);
        } catch (ProductSearchCancelled $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            // The full exception message is deliberately not echoed back -
            // it can carry provider/request detail that does not belong in a
            // tool result. Only a known, safe classification is: whether the
            // provider still rejected a prepared image as not a valid image
            // (residual - VisionImagePreparer already converts every format
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
