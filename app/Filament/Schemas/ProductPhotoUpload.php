<?php

namespace App\Filament\Schemas;

use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class ProductPhotoUpload
{
    public static function make(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->label('Фотографии')
            ->disk('public')
            ->visibility('public')
            ->image()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
            ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::ulid().'.'.$file->guessExtension())
            ->maxSize(8192)
            ->preventFilePathTampering()
            ->imageEditor()
            ->imagePreviewHeight('200')
            ->columnSpanFull();
    }

    public static function multiple(string $name = 'new_photos'): FileUpload
    {
        return self::make($name)
            ->multiple()
            ->reorderable()
            ->appendFiles()
            ->storeFiles(false)
            ->helperText('Перетащите фотографии сюда. Можно изменить порядок и обрезать изображение. JPEG, PNG, WebP или AVIF, до 8 МБ на файл. Новые фото добавятся к существующим; первая фотография пустой галереи станет главной.');
    }
}
