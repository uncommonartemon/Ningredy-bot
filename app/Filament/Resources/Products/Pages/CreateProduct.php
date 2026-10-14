<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\Pages\Concerns\HandlesProductPhotoUploads;
use App\Filament\Resources\Products\Pages\Concerns\InteractsWithProductFormAssistant;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\ProductVariantFormState;
use App\Models\Brand;
use App\Models\Category;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use HandlesProductPhotoUploads;
    use InteractsWithProductFormAssistant;

    protected static string $resource = ProductResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    public static bool $formActionsAreSticky = true;

    protected function beforeValidate(): void
    {
        $this->materializeProductAiProposals();
    }

    protected function preserveFormDataWhenCreatingAnother(array $data): array
    {
        $category = Category::find($data['category_id'] ?? null);

        return [
            'category_id' => $category?->id,
            'brand_id' => Brand::whereKey($data['brand_id'] ?? null)->value('id'),
            'product_type' => $category?->product_type_affinity ?: ($data['product_type'] ?? 'other'),
            'variants' => ProductVariantFormState::fresh($category?->id),
        ];
    }
}
