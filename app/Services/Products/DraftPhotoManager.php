<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use RuntimeException;

class DraftPhotoManager
{
    public function delete(ProductDraft $draft, ProductDraftMedia $media): void
    {
        $this->guard($draft, $media);

        if ($draft->media()->count() <= 1) {
            throw new RuntimeException('Нельзя удалить последнее фото черновика.');
        }

        $media->delete();
        $this->normalize($draft);
    }

    public function normalize(ProductDraft $draft): void
    {
        $draft->media()
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->values()
            ->each(function (ProductDraftMedia $media, int $index): void {
                $media->update([
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                    'role' => $index === 0 ? 'primary' : ($index === 1 ? 'secondary' : 'detail'),
                ]);
            });
    }

    public function guard(ProductDraft $draft, ProductDraftMedia $media): void
    {
        if ($draft->status !== 'pending_review' || $media->product_draft_id !== $draft->id) {
            throw new RuntimeException('Черновик или фотография уже недоступны.');
        }
    }
}
