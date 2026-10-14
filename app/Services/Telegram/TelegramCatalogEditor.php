<?php

namespace App\Services\Telegram;

use App\Models\AiRun;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Products\CatalogLocales;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** A catalog edit stages a separate, owned draft; the existing product remains unchanged. */
class TelegramCatalogEditor
{
    private const ACCEPTED_MEDIA = ['verified', 'source_verified', 'manual'];

    public function createDraft(Product $product, TelegramUpdate $update, ?int $variantId = null): ProductDraft
    {
        $copiedPaths = [];

        try {
            return DB::transaction(function () use ($product, $update, $variantId, &$copiedPaths): ProductDraft {
                $product = Product::query()->lockForUpdate()->findOrFail($product->id);
                $update = TelegramUpdate::query()->lockForUpdate()->findOrFail($update->id);
                app(ProductSearchCancellation::class)->throwIfCancelled($update->id);
                $variant = $variantId === null ? $this->variant($product) : $product->variants()->whereKey($variantId)->first();
                throw_if($variantId !== null && $variant === null, RuntimeException::class,
                    'Выбранная комплектация не относится к этому товару или уже удалена.');
                $existing = $update->productDrafts()->first();
                if ($existing) {
                    throw_unless((int) ($existing->manualIntake()['target_product_id'] ?? 0) === $product->id
                        && (int) ($existing->manualIntake()['target_variant_id'] ?? 0) === (int) $variant?->id,
                        RuntimeException::class, 'Это сообщение уже относится к другому черновику.');

                    return $existing;
                }

                $attributes = $this->attributes($product, $variant);
                $specifications = $attributes->map(fn (AttributeValue $value): array => [
                    'key' => $value->definition->key, 'name' => $value->definition->label,
                    'value' => $value->value, 'unit' => $value->unit, 'source_url' => null, 'source_excerpt' => null,
                ])->values()->all();
                foreach (['sku' => 'SKU', 'mpn' => 'Part number', 'gtin' => 'GTIN'] as $key => $name) {
                    if (filled($variant?->{$key}) && ! collect($specifications)->contains('key', $key)) {
                        $specifications[] = ['key' => $key, 'name' => $name, 'value' => $variant->{$key}, 'source_url' => null, 'source_excerpt' => null];
                    }
                }
                $translations = $variant?->translations ?: ($product->translations ?? []);
                foreach (array_keys(CatalogLocales::CONTENT_TRANSLATIONS) as $locale) {
                    $translated = $attributes->filter(fn (AttributeValue $value): bool => filled(($value->translations ?? [])[$locale] ?? null))
                        ->map(fn (AttributeValue $value): array => [
                            'key' => $value->definition->key,
                            'name' => ($value->definition->translations ?? [])[$locale] ?? $value->definition->label,
                            'value' => $value->translations[$locale],
                        ])->values()->all();
                    if ($translated !== []) {
                        $translations[$locale]['specifications'] = $translated;
                    }
                }
                $intake = [
                    'origin' => 'catalog_edit', 'status' => 'needs_input', 'missing_fields' => [], 'conflicts' => [],
                    'original_description' => $product->description, 'telegram_update_ids' => [$update->id], 'uploaded_photos' => [],
                    'target_product_id' => $product->id, 'target_variant_id' => $variant?->id,
                    'catalog_fingerprint' => $this->fingerprint($product, $variant),
                    'commercial' => ['price_amount' => $variant?->price, 'currency' => $variant?->currency,
                        'quantity' => $variant?->quantity, 'warranty_months' => $variant?->warranty_months],
                ];
                $payload = $update->payload ?? [];
                $payload['_manual_product_upload'] = true;
                $payload['_manual_product_intake'] = $intake;
                $update->update(['payload' => $payload]);
                // The draft schema requires an audit id even for deterministic local work.
                // This is explicitly a local snapshot, never a fabricated provider invocation.
                $snapshot = AiRun::query()->create([
                    'telegram_update_id' => $update->id, 'provider' => 'local', 'model' => 'catalog_snapshot',
                    'status' => 'completed', 'prompt' => 'Prepare catalog product #'.$product->id.' for operator editing.',
                    'started_at' => now(), 'completed_at' => now(),
                    'activity' => ['operation' => 'catalog_snapshot', 'product_id' => $product->id],
                    'response' => ['source' => 'catalog_edit', 'no_provider_invocation' => true,
                        'target_product_id' => $product->id, 'target_variant_id' => $variant?->id],
                    'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cache_read_input_tokens' => 0,
                        'reasoning_tokens' => 0, 'web_search_calls' => 0],
                ]);
                $draft = ProductDraft::query()->create([
                    'telegram_update_id' => $update->id, 'ai_run_id' => $snapshot->id, 'requested_by_telegram_user_id' => $update->telegram_user_id,
                    'status' => 'pending_review', 'title' => $product->title, 'brand' => $product->brand?->name,
                    'model' => $product->model, 'product_type' => $product->product_type,
                    'category' => $product->category?->slug, 'description' => $product->description,
                    'condition' => $variant?->condition ?? 'new', 'color' => $variant?->color,
                    'translations' => $translations, 'specifications' => $specifications, 'specification_evidence' => [],
                    'sources' => [], 'image_urls' => [], 'confidence' => $product->confidence,
                    'gallery_status' => 'partial', 'gallery_notes' => 'Копия фотографий из каталога для отдельного подтверждения правок.',
                ]);
                $media = $this->media($product, $variant);
                foreach ($media as $source) {
                    if (! in_array($source->verification_status, self::ACCEPTED_MEDIA, true) || ! $source->disk || ! $source->path
                        || ! Storage::disk($source->disk)->exists($source->path)) {
                        $intake['uploaded_photos'][] = ['catalog_media_id' => $source->id, 'status' => 'unavailable'];

                        continue;
                    }
                    $bytes = Storage::disk($source->disk)->get($source->path);
                    $dimensions = @getimagesizefromstring($bytes);
                    if (! $dimensions) {
                        $intake['uploaded_photos'][] = ['catalog_media_id' => $source->id, 'status' => 'invalid_image'];

                        continue;
                    }
                    $extension = match ($dimensions['mime'] ?? '') {
                        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif',
                        default => null,
                    };
                    if ($extension === null) {
                        continue;
                    }
                    $path = "product-drafts/{$draft->id}/catalog-edit/".Str::uuid().'.'.$extension;
                    throw_unless(Storage::disk('public')->put($path, $bytes), RuntimeException::class, 'Не удалось сохранить копию фотографии.');
                    $copiedPaths[] = $path;
                    $order = $draft->media()->count();
                    $copy = $draft->media()->create([
                        'disk' => 'public', 'path' => $path, 'source_url' => $source->source_url,
                        'role' => $order === 0 ? 'primary' : ($order === 1 ? 'secondary' : 'detail'),
                        'mime_type' => $dimensions['mime'], 'width' => $dimensions[0], 'height' => $dimensions[1],
                        'file_size' => strlen($bytes), 'checksum' => hash('sha256', $bytes),
                        'verification_status' => $source->verification_status,
                        'verification_score' => $source->verification_score, 'verification_model' => $source->verification_model,
                        'verification_notes' => 'Скопировано из каталога, фото #'.$source->id.'. '.$source->verification_notes,
                        'sort_order' => $order, 'is_primary' => $order === 0,
                    ]);
                    $intake['uploaded_photos'][] = ['catalog_media_id' => $source->id, 'draft_media_id' => $copy->id, 'status' => 'copied'];
                }
                if (trim((string) $draft->title) === '') {
                    $intake['missing_fields'][] = 'title';
                }
                if (! $product->category?->is_active) {
                    $intake['missing_fields'][] = 'category';
                }
                if (! $draft->media()->exists()) {
                    $intake['missing_fields'][] = 'product_photo';
                }
                $intake['status'] = $intake['missing_fields'] === [] ? 'ready' : 'needs_input';
                $payload['_manual_product_intake'] = $intake;
                $update->update(['payload' => $payload]);
                $draft->update(['gallery_status' => $draft->media()->exists() ? 'complete' : 'failed',
                    'images_staged_at' => $draft->media()->exists() ? now() : null]);

                return $draft->fresh(['telegramUpdate', 'media']);
            });
        } catch (Throwable $exception) {
            // Delete only fresh copies owned by this failed draft, never catalog originals.
            Storage::disk('public')->delete($copiedPaths);

            throw $exception;
        }
    }

    /** Optimistic lock for approval: any changed target data requires a fresh edit draft. */
    public function fingerprint(Product $product, ?ProductVariant $variant = null): string
    {
        $product = $product->fresh(['brand', 'category']);
        $variant = $variant?->fresh() ?? $this->variant($product);

        return hash('sha256', json_encode([
            'product' => $product->only(['id', 'title', 'model', 'brand_id', 'category_id', 'product_type', 'description',
                'translations', 'status', 'is_active', 'confidence', 'canonical_key']),
            'brand' => $product->brand?->name, 'category' => $product->category?->only(['id', 'slug', 'is_active']),
            'variant' => $variant?->only(['id', 'product_id', 'fingerprint', 'name', 'sku', 'mpn', 'gtin', 'color', 'condition',
                'price', 'compare_at_price', 'currency', 'quantity', 'stock_status', 'warranty_months', 'is_default', 'is_active', 'translations']),
            'attributes' => $this->attributes($product, $variant)->map(fn (AttributeValue $attribute): array => [
                ...$attribute->only(['id', 'product_id', 'product_variant_id', 'attribute_definition_id', 'value', 'unit', 'translations']),
                'definition' => $attribute->definition->only(['key', 'label', 'translations']),
            ])->values()->all(),
            'media' => $this->media($product, $variant)->map(fn ($media): array => $media->only([
                'id', 'product_variant_id', 'disk', 'path', 'checksum', 'verification_status', 'sort_order', 'is_primary',
            ]))->values()->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function variant(Product $product): ?ProductVariant
    {
        return $product->variants()->orderByDesc('is_default')->orderBy('id')->first();
    }

    private function attributes(Product $product, ?ProductVariant $variant): Collection
    {
        $global = $product->attributes()->whereNull('product_variant_id')->with('definition')->orderBy('id')->get()->keyBy('attribute_definition_id');
        $selected = $variant?->attributes()->with('definition')->orderBy('id')->get()->keyBy('attribute_definition_id') ?? collect();

        return $global->replace($selected)->sortBy('attribute_definition_id')->values();
    }

    private function media(Product $product, ?ProductVariant $variant): Collection
    {
        return $product->media()->where('type', 'image')
            ->where(fn ($query) => $query->whereNull('product_variant_id')->when($variant, fn ($query) => $query->orWhere('product_variant_id', $variant->id)))
            ->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')->get();
    }
}
