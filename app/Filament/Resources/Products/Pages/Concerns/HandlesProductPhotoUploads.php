<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use App\Services\Products\ProductMediaUploader;

trait HandlesProductPhotoUploads
{
    protected function afterCreate(): void
    {
        $this->saveNewProductPhotos();
    }

    protected function afterSave(): void
    {
        $this->saveNewProductPhotos();
    }

    private function saveNewProductPhotos(): void
    {
        app(ProductMediaUploader::class)->upload($this->getRecord(), $this->data['new_photos'] ?? []);
        $this->data['new_photos'] = [];
    }
}
