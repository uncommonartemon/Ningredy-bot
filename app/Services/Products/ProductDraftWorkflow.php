<?php

namespace App\Services\Products;

use App\Jobs\StoreProductImages;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductDraftWorkflow
{
    private const FILTERABLE_ATTRIBUTE_KEYS = [
        'cpu', 'gpu', 'ram', 'storage', 'display', 'screen_size', 'refresh_rate',
    ];

    public function __construct(
        private readonly ProductPublicDescription $publicDescription,
    ) {}

    public function approve(
        ProductDraft $draft,
        ?User $reviewer = null,
        ?string $telegramReviewerId = null,
    ): Product {
        try {
            return $this->doApprove($draft, $reviewer, $telegramReviewerId);
        } catch (UniqueConstraintViolationException) {
            // Two approvals for the same product raced (near-simultaneous
            // double-click/retry): the other one committed first, so
            // canonical_key now exists. Retry once - firstOrNew() will find
            // it and update instead of trying to insert a duplicate again.
            return $this->doApprove($draft, $reviewer, $telegramReviewerId);
        }
    }

    private function doApprove(
        ProductDraft $draft,
        ?User $reviewer,
        ?string $telegramReviewerId,
    ): Product {
        return DB::transaction(function () use ($draft, $reviewer, $telegramReviewerId): Product {
            $normalizedDescription = $this->publicDescription->normalize([
                'title' => $draft->title,
                'model' => $draft->model,
                'color' => $draft->color,
                'description' => $draft->description,
                'research_notes' => $draft->research_notes,
                'specifications' => $draft->specifications,
            ]);
            $draft->update($normalizedDescription);
            $productType = in_array($draft->product_type, ['laptop', 'desktop', 'component', 'other'], true)
                ? $draft->product_type
                : $this->guessProductType($draft);
            $category = ($draft->category ? Category::query()->where('slug', $draft->category)->first() : null)
                ?? Category::query()->where('slug', match ($productType) {
                    'laptop' => 'laptops',
                    'desktop' => 'computers',
                    'component' => 'components',
                    default => 'other-tech',
                })->firstOrFail();

            $brand = $this->resolveBrand($draft->brand);
            $canonicalKey = $this->canonicalProductKey($draft);
            $product = Product::query()->firstOrNew(['canonical_key' => $canonicalKey]);

            if (! $product->exists) {
                $product->slug = $this->uniqueSlug($draft);
            }

            $product->fill([
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'product_type' => $productType,
                'status' => 'published',
                'title' => $draft->title,
                'model' => $draft->model,
                'description' => $normalizedDescription['description'],
                'confidence' => max((float) $product->confidence, (float) $draft->confidence),
                'is_active' => true,
                'published_at' => $product->published_at ?: now(),
            ])->save();

            $variant = $this->upsertVariant($product, $draft);
            $this->syncAttributes($product, $variant, $category, $draft->specifications ?? []);
            $this->syncSources($product, $variant, $draft);

            $draft->update([
                'status' => 'approved',
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $reviewer?->id,
                'reviewed_by_telegram_user_id' => $telegramReviewerId,
                'rejection_reason' => null,
                'approved_product_id' => $product->id,
                'approved_variant_id' => $variant->id,
            ]);

            StoreProductImages::dispatch($product->id, $variant->id, $draft->id)->afterCommit();

            return $product;
        });
    }

    public function reject(
        ProductDraft $draft,
        ?User $reviewer = null,
        ?string $telegramReviewerId = null,
        ?string $reason = null,
    ): void {
        $draft->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $reviewer?->id,
            'reviewed_by_telegram_user_id' => $telegramReviewerId,
            'rejection_reason' => $reason,
        ]);
    }

    private function resolveBrand(?string $name): ?Brand
    {
        if (blank($name)) {
            return null;
        }

        $slug = Str::slug($name);

        return Brand::query()->firstOrCreate(
            ['slug' => $slug ?: 'brand-'.sha1(Str::lower($name))],
            ['name' => trim($name), 'is_active' => true],
        );
    }

    private function canonicalProductKey(ProductDraft $draft): string
    {
        return ProductIdentityKey::for($draft->brand, $draft->model, $draft->title);
    }

    private function uniqueSlug(ProductDraft $draft): string
    {
        $base = Str::slug($draft->title) ?: "product-{$draft->id}";

        return Product::query()->where('slug', $base)->exists() ? "{$base}-{$draft->id}" : $base;
    }

    private function upsertVariant(Product $product, ProductDraft $draft): ProductVariant
    {
        $specifications = collect($draft->specifications ?? [])
            ->mapWithKeys(fn (mixed $item, mixed $key): array => [
                Str::lower((string) (is_array($item) ? ($item['name'] ?? $key) : $key)) => Str::lower(trim((string) (is_array($item) ? ($item['value'] ?? '') : $item))),
            ])->sortKeys()->all();
        $fingerprint = sha1(json_encode([
            'color' => Str::lower((string) $draft->color),
            'specifications' => $specifications,
        ], JSON_UNESCAPED_UNICODE));
        $variant = ProductVariant::query()->firstOrNew([
            'product_id' => $product->id,
            'fingerprint' => $fingerprint,
        ]);

        $variant->fill([
            'name' => $draft->color ?: 'Базовая конфигурация',
            'color' => $draft->color,
            'condition' => 'new',
            'currency' => 'CZK',
            'stock_status' => 'unknown',
            'is_default' => $variant->exists ? $variant->is_default : ! $product->variants()->exists(),
            'is_active' => true,
        ])->save();

        return $variant;
    }

    private function syncAttributes(Product $product, ProductVariant $variant, Category $category, array $specifications): void
    {
        foreach ($specifications as $key => $specification) {
            $label = is_array($specification)
                ? ($specification['name'] ?? $specification['label'] ?? (is_string($key) ? $key : 'Характеристика'))
                : (is_string($key) ? $key : 'Характеристика');
            $rawValue = is_array($specification) ? ($specification['value'] ?? null) : $specification;

            if (! is_scalar($rawValue) || blank((string) $rawValue)) {
                continue;
            }

            $suppliedKey = is_array($specification) ? ($specification['key'] ?? null) : null;
            $attributeKey = is_string($suppliedKey) && preg_match('/^[a-z0-9_]+$/', $suppliedKey) === 1
                ? $this->canonicalAttributeKey($suppliedKey)
                : $this->canonicalAttributeKey((string) $label);
            [$dataType, $numericValue, $unit] = $this->typedValue($attributeKey, (string) $rawValue);
            $isFilterable = in_array($attributeKey, self::FILTERABLE_ATTRIBUTE_KEYS, true);
            $definition = AttributeDefinition::query()->updateOrCreate(
                ['key' => $attributeKey],
                [
                    'label' => $this->canonicalLabel($attributeKey, (string) $label),
                    'data_type' => $dataType,
                    'default_unit' => $unit,
                    'is_filterable' => $isFilterable,
                    'is_variant' => $isFilterable,
                ],
            );
            $definition->categories()->syncWithoutDetaching([$category->id]);
            $owner = $isFilterable ? $variant->attributes() : $product->attributes();
            $owner->updateOrCreate(
                ['attribute_definition_id' => $definition->id],
                [
                    'product_id' => $isFilterable ? null : $product->id,
                    'product_variant_id' => $isFilterable ? $variant->id : null,
                    'value' => trim((string) $rawValue),
                    'normalized_value' => Str::lower(trim((string) $rawValue)),
                    'numeric_value' => $numericValue,
                    'unit' => $unit,
                ],
            );
        }
    }

    private function syncSources(Product $product, ProductVariant $variant, ProductDraft $draft): void
    {
        foreach ($draft->sources ?? [] as $source) {
            $url = $source['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $product->sources()->firstOrCreate(
                ['url' => $url, 'product_variant_id' => $variant->id],
                [
                    'product_draft_id' => $draft->id,
                    'title' => $source['title'] ?? (parse_url($url, PHP_URL_HOST) ?: 'Источник'),
                    'domain' => parse_url($url, PHP_URL_HOST),
                    'source_type' => in_array(($source['type'] ?? null), [
                        'manufacturer', 'retailer', 'marketplace', 'review', 'database', 'web',
                    ], true) ? $source['type'] : 'web',
                    'retrieved_at' => now(),
                    'confidence' => $draft->confidence,
                ],
            );
        }
    }

    private function guessProductType(ProductDraft $draft): string
    {
        $haystack = Str::lower($draft->title.' '.$draft->model.' '.json_encode($draft->specifications));
        // Component keywords (cpu, ram, ssd…) appear in laptop/desktop specs too,
        // so they are matched against the identity only, not the specifications.
        $identity = Str::lower($draft->title.' '.$draft->model);

        return match (true) {
            Str::contains($haystack, [
                'laptop', 'notebook', 'ноутбук', 'macbook', 'legion', 'ideapad', 'thinkpad',
                'vivobook', 'zenbook', 'pavilion', 'aspire', 'inspiron', 'surface laptop',
            ]) => 'laptop',
            Str::contains($haystack, ['desktop', 'workstation', 'компьютер', 'готовый пк', 'mini pc']) => 'desktop',
            Str::contains($identity, [
                'gpu', 'cpu', 'видеокарт', 'процессор', 'motherboard', 'материн', 'ram', 'оператив',
                'ssd', 'hdd', 'накопител', 'power supply', 'блок питания', 'cooler', 'кулер',
                'корпус', 'case', 'monitor', 'монитор', 'keyboard', 'клавиатур', 'mouse', 'мыш',
            ]) => 'component',
            default => 'other',
        };
    }

    private function canonicalAttributeKey(string $label): string
    {
        $label = Str::lower($label);

        return match (true) {
            Str::contains($label, ['cpu', 'processor', 'процессор']) => 'cpu',
            Str::contains($label, ['gpu', 'graphics', 'video', 'видео']) => 'gpu',
            Str::contains($label, ['ram', 'memory', 'оператив']) => 'ram',
            Str::contains($label, ['storage', 'ssd', 'накопител']) => 'storage',
            Str::contains($label, ['refresh', 'частот']) => 'refresh_rate',
            Str::contains($label, ['screen size', 'diagonal', 'диагонал']) => 'screen_size',
            Str::contains($label, ['display', 'screen', 'экран']) => 'display',
            in_array($label, self::FILTERABLE_ATTRIBUTE_KEYS, true) => $label,
            default => Str::slug($label, '_') ?: 'attribute_'.substr(sha1($label), 0, 12),
        };
    }

    private function canonicalLabel(string $key, string $fallback): string
    {
        return match ($key) {
            'cpu' => 'Процессор',
            'gpu' => 'Видеокарта',
            'ram' => 'Оперативная память',
            'storage' => 'Накопитель',
            'display' => 'Дисплей',
            'screen_size' => 'Диагональ',
            'refresh_rate' => 'Частота экрана',
            default => trim($fallback),
        };
    }

    private function typedValue(string $key, string $value): array
    {
        $numberKeys = ['ram', 'storage', 'screen_size', 'refresh_rate'];

        if (! in_array($key, $numberKeys, true) || preg_match('/(\d+(?:[.,]\d+)?)/', $value, $matches) !== 1) {
            return ['text', null, null];
        }

        $number = (float) str_replace(',', '.', $matches[1]);
        $lower = Str::lower($value);
        $unit = match ($key) {
            'ram', 'storage' => 'GB',
            'screen_size' => 'inch',
            'refresh_rate' => 'Hz',
        };

        if ($key === 'storage' && Str::contains($lower, ['tb', 'тб'])) {
            $number *= 1024;
        }

        return ['number', $number, $unit];
    }
}
