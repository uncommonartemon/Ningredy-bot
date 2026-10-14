<?php

namespace App\Services\Products;

use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductDraft;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** A whole-form revision: relationship repeaters must not delete newer rows. */
class CatalogEditRevision
{
    public function fingerprint(Product|ProductDraft $record, bool $lock = false): string
    {
        $record = $record->newQuery()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($record->getKey());
        $rows = fn ($query): array => $query->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()->map(fn (Model $model): array => $model->getAttributes())->all();
        $data = ['record' => $record->getAttributes(), 'media' => $rows($record->media())];
        if ($record instanceof Product) {
            $data['variants'] = $rows($record->variants());
            $variantIds = array_column($data['variants'], 'id');
            $data['attributes'] = $rows(AttributeValue::query()->where(fn ($query) => $query
                ->where('product_id', $record->id)->orWhereIn('product_variant_id', $variantIds)));
            $data['sources'] = $rows($record->sources());
        } else {
            $data['manual_intake'] = $record->manualIntake();
        }

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Called inside EditRecord's transaction, before getState saves any relationships. */
    public function assertCurrent(Product|ProductDraft $record, ?string $revision): void
    {
        if (! $revision || ! hash_equals($revision, $this->fingerprint($record, lock: true))) {
            throw ValidationException::withMessages(['data.title' => __('Карточка изменена после открытия формы. Обновите страницу перед сохранением, чтобы не потерять новые данные.')]);
        }
    }
}
