<?php

namespace App\Services\Telegram;

use App\Models\AiOperation;
use App\Models\Product;
use App\Models\TelegramUpdate;
use Illuminate\Support\Facades\DB;

class TelegramCatalogDeletion
{
    /** Every configuration is part of the confirmation, not only the default one. */
    public function fingerprint(Product $product): string
    {
        $product = $product->fresh();
        $snapshot = ['product' => $product->getAttributes()];
        foreach (['variants', 'attributes', 'media', 'sources'] as $relation) {
            $snapshot[$relation] = $product->{$relation}()->orderBy('id')->get()
                ->map(fn ($row): array => $row->getAttributes())->all();
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    /** @return array{status: string, message: string, notify?: bool} */
    public function resolve(int $operationId, string $action, TelegramUpdate $update): array
    {
        return DB::transaction(function () use ($operationId, $action, $update): array {
            $operation = AiOperation::query()->with('telegramUpdate')->lockForUpdate()->find($operationId);
            if (! $operation || $operation->action !== 'delete_product' || $operation->target_type !== Product::class) {
                return ['status' => 'not_found', 'message' => 'Операция удаления не найдена.'];
            }
            if (! hash_equals((string) $operation->telegram_user_id, (string) $update->telegram_user_id)
                || ! hash_equals((string) $operation->telegramUpdate?->chat_id, (string) $update->chat_id)) {
                return ['status' => 'rejected', 'message' => 'Эта операция создана в другом чате или другим пользователем.'];
            }
            if ($operation->status !== 'awaiting_confirmation') {
                return ['status' => 'already_processed', 'message' => "Операция уже обработана: {$operation->status}."];
            }
            $productId = (int) $operation->target_id;
            if ($action === 'cancel') {
                $operation->update(['status' => 'cancelled', 'result' => ['cancelled_by_update_id' => $update->id], 'executed_at' => now()]);

                return ['status' => 'completed', 'message' => "Удаление товара #{$productId} отменено.", 'notify' => true];
            }
            // Legacy confirmations have no reviewed snapshot and must be requested again.
            $expires = data_get($operation->payload, 'expires_at');
            $expected = (string) data_get($operation->payload, 'catalog_fingerprint');
            if (! is_numeric($expires) || (int) $expires <= now()->getTimestamp() || $expected === '') {
                $operation->update(['status' => 'expired', 'executed_at' => now()]);

                return ['status' => 'completed', 'message' => "Подтверждение устарело. Отправьте /delete {$productId} заново.", 'notify' => true];
            }
            $product = Product::query()->lockForUpdate()->find($productId);
            if (! $product) {
                $operation->update(['status' => 'failed', 'error' => 'Product was not found at confirmation time.', 'executed_at' => now()]);

                return ['status' => 'not_found', 'message' => 'Товар уже отсутствует.'];
            }
            if (! hash_equals($expected, $this->fingerprint($product))) {
                $operation->update(['status' => 'stale', 'error' => 'Product changed after deletion was requested.', 'executed_at' => now()]);

                return ['status' => 'completed', 'message' => "Товар изменился после запроса удаления. Проверьте карточку и отправьте /delete {$productId} заново.", 'notify' => true];
            }
            $title = $product->title;
            $product->delete();
            $operation->update(['status' => 'completed', 'result' => ['deleted_product_id' => $productId,
                'title' => $title, 'confirmed_by_update_id' => $update->id], 'executed_at' => now()]);

            return ['status' => 'completed', 'message' => "🗑 Товар #{$productId} «{$title}» удалён навсегда. Операция #{$operation->id}.", 'notify' => true];
        });
    }
}
