<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductImageVisionAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use GdImage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Throwable;

class ProductImageVisionVerifier
{
    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    public function select(ProductDraft $draft, array $candidates, int $limit): array
    {
        if ($limit <= 0 || $candidates === []) {
            return [];
        }

        $maxCandidates = (int) config('product-images.vision_candidates', 4);
        $minimumScore = (int) config('product-images.vision_min_score', 70);
        $officialMinimumScore = (int) config('product-images.vision_official_min_score', 55);
        $candidates = array_slice($candidates, 0, $maxCandidates);
        $provider = (string) config('services.product_image_vision.provider', 'openai');
        $model = (string) config('services.product_image_vision.model', 'gpt-5.4-mini');
        $prompt = $this->prompt($draft, $candidates);
        $run = AiRun::query()->create([
            'telegram_update_id' => $draft->telegram_update_id,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $prompt,
            'started_at' => now(),
        ]);

        try {
            $attachments = array_map(fn (array $candidate, int $index) => Image::fromBase64(
                base64_encode($this->thumbnail($candidate['image'])),
                'image/webp',
            )->as('candidate-'.($index + 1).'.webp')->withProviderOptions(['detail' => 'high']), $candidates, array_keys($candidates));

            $response = ProductImageVisionAgent::make()->prompt(
                $prompt,
                attachments: $attachments,
                provider: $provider,
                model: $model,
                timeout: (int) config('services.product_image_vision.timeout', 45),
            );
            $data = Validator::make($response->toArray(), [
                'images' => ['required', 'array', 'size:'.count($candidates)],
                'images.*.index' => ['required', 'integer', 'between:1,'.count($candidates), 'distinct'],
                'images.*.exact_match' => ['required', 'boolean'],
                'images.*.publishable' => ['required', 'boolean'],
                'images.*.kind' => ['required', 'in:product,packaging,detail,logo,banner,screenshot,unrelated,uncertain'],
                'images.*.view' => ['required', 'in:front,angle,side,back,detail,packaging,other'],
                'images.*.gallery_rank' => ['required', 'integer', 'between:1,'.count($candidates), 'distinct'],
                'images.*.score' => ['required', 'integer', 'between:0,100'],
                'images.*.reason' => ['required', 'string', 'max:1000'],
            ])->validate();

            $run->update([
                'invocation_id' => $response->invocationId,
                'status' => 'completed',
                'response' => $data,
                'usage' => $response->usage->toArray(),
                'completed_at' => now(),
            ]);

            $reviews = collect($data['images'])
                ->map(function (array $review) use ($draft, $candidates): array {
                    $candidate = $candidates[$review['index'] - 1] ?? [];

                    return [
                        ...$review,
                        'hero' => $review['kind'] === 'product'
                            && in_array($review['view'], ['front', 'angle'], true),
                        'source_supported' => $this->sourceSupportsIdentity($draft, (string) ($candidate['source_url'] ?? '')),
                        'official_source' => ($candidate['source_priority'] ?? null) === 'official',
                        'source_rank' => match ($candidate['source_priority'] ?? null) {
                            'official' => 2,
                            'amazon' => 1,
                            default => 0,
                        },
                    ];
                })
                ->filter(fn (array $review): bool => $review['publishable']
                    && in_array($review['kind'], ['product', 'packaging', 'detail'], true)
                    && $review['score'] >= ($review['official_source'] ? $officialMinimumScore : $minimumScore)
                    && ($review['exact_match'] || $review['source_supported'] || $review['official_source']))
                ->when(
                    config('product-images.ranking', 'heuristic') === 'model',
                    // The model orders the gallery itself via gallery_rank.
                    fn ($query) => $query->sortBy(fn (array $review): array => [
                        $review['gallery_rank'],
                        -$review['score'],
                    ]),
                    // Code heuristics: front hero first, then official source,
                    // exact match, product kind, score.
                    fn ($query) => $query->sortByDesc(fn (array $review): array => [
                        $review['hero'] ? 1 : 0,
                        $review['source_rank'],
                        $review['exact_match'] ? 1 : 0,
                        $review['kind'] === 'product' ? 1 : 0,
                        $review['score'],
                    ]),
                )
                ->values();

            return $reviews->take($limit)->map(function (array $review) use ($candidates, $model): array {
                return [
                    ...$candidates[$review['index'] - 1],
                    'vision_kind' => $review['kind'],
                    'vision_score' => $review['score'],
                    'vision_reason' => match (true) {
                        $review['official_source'] => 'Official manufacturer source. '.$review['reason'],
                        $review['source_supported'] && ! $review['exact_match'] => 'Exact identity is supported by the source URL. '.$review['reason'],
                        default => $review['reason'],
                    },
                    'vision_model' => $model,
                ];
            })->all();
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);
            report($exception);

            throw $exception;
        }
    }

    private function sourceSupportsIdentity(ProductDraft $draft, string $sourceUrl): bool
    {
        if ($sourceUrl === '') {
            return false;
        }

        $source = Str::lower(Str::ascii(urldecode($sourceUrl)));
        $identity = Str::lower(Str::ascii((string) ($draft->model ?: $draft->title)));
        $tokens = collect(preg_split('/[^a-z0-9]+/', $identity) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 2)
            ->reject(fn (string $token): bool => in_array($token, [
                'product', 'processor', 'laptop', 'notebook', 'tablet', 'graphics', 'card', 'edition',
            ], true))
            ->unique()
            ->values();

        if ($tokens->contains(fn (string $token): bool => strlen($token) >= 3
            && preg_match('/[a-z]/', $token) === 1
            && preg_match('/\d/', $token) === 1
            && str_contains($source, $token))) {
            return true;
        }

        if ($tokens->contains(fn (string $token): bool => strlen($token) >= 4
            && ctype_digit($token)
            && str_contains($source, $token))) {
            return true;
        }

        return $tokens
            ->filter(fn (string $token): bool => strlen($token) >= 3 && str_contains($source, $token))
            ->count() >= 2;
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function prompt(ProductDraft $draft, array $candidates): string
    {
        $count = count($candidates);
        $specifications = collect($draft->specifications ?? [])->map(function (array $item): string {
            return trim(($item['name'] ?? '').': '.($item['value'] ?? ''), ': ');
        })->filter()->take(12)->implode('; ');
        $candidateSources = collect($candidates)->map(
            fn (array $candidate, int $index): string => '#'.($index + 1)
                .(($candidate['source_priority'] ?? null) === 'official' ? ' [OFFICIAL MANUFACTURER]' : '')
                .(($candidate['source_priority'] ?? null) === 'amazon' ? ' [AMAZON]' : '')
                .' source: '.($candidate['source_url'] ?? 'unknown'),
        )->implode("\n");

        return <<<PROMPT
            Review {$count} attached candidate images for a public product catalog.
            Exact requested product: {$draft->title}
            Brand: {$draft->brand}
            Model: {$draft->model}
            Color/version: {$draft->color}
            Key specifications: {$specifications}
            Numbering follows attachment order: first attachment is image 1, etc.
            Candidate source URLs (supporting evidence only; visible conflicts always win):
            {$candidateSources}
            PROMPT;
    }

    private function thumbnail(GdImage $image): string
    {
        $ratio = min(640 / imagesx($image), 640 / imagesy($image), 1);
        $width = max(1, (int) round(imagesx($image) * $ratio));
        $height = max(1, (int) round(imagesy($image) * $ratio));
        $thumbnail = imagescale($image, $width, $height);

        ob_start();
        imagewebp($thumbnail, null, 78);
        $bytes = ob_get_clean();
        imagedestroy($thumbnail);

        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('Could not prepare an image thumbnail for Vision.');
        }

        return $bytes;
    }
}
