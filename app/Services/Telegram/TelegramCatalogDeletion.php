<?php

namespace App\Services\Telegram;

use App\Models\AiOperation;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\Catalog\StockUnits;
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

    /**
     * The question asked before a deletion, the same wherever it was requested. Deleting is for
     * a card that was a mistake: a product that will come in again is taken off sale instead,
     * so that way out stands beside the deletion (operator's decision, 2026-11-11).
     *
     * @return array{0: string, 1: array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}}
     */
    public function confirmation(AiOperation $operation): array
    {
        $product = Product::query()->find($operation->target_id);
        $title = $product?->title ?? (string) data_get($operation->payload, 'title', 'Товар');
        $rows = [[['text' => 'Удалить навсегда', 'callback_data' => "product:delete:confirm:{$operation->id}"]]];
        if ($product?->isOnSale()) {
            $rows[] = [['text' => '💤 Снять с продажи', 'callback_data' => "product:delete:sleep:{$operation->id}"]];
        }
        $rows[] = [['text' => 'Отмена', 'callback_data' => "product:delete:cancel:{$operation->id}"]];

        return ["Удалить товар #{$operation->target_id} «{$title}» навсегда?\n\n"
            .'Удалятся карточка, конфигурации, характеристики и фото; продажи останутся в учёте. '
            .($product?->isOnSale()
                ? 'Если такой товар может появиться снова, снимите его с продажи: карточка сохранится и не придётся искать заново.'
                : 'Сейчас товар снят с продажи: карточка сохранена и ждёт, когда такой товар появится снова.')
            ."\n\nПодтверждение действует 10 минут.", ['inline_keyboard' => $rows]];
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
            // The other way out, chosen on the confirmation itself: nothing is deleted.
            if ($action === 'sleep') {
                $product = Product::query()->lockForUpdate()->find($productId);
                if (! $product) {
                    $operation->update(['status' => 'failed', 'error' => 'Product was not found at confirmation time.', 'executed_at' => now()]);

                    return ['status' => 'not_found', 'message' => 'Товар уже отсутствует.'];
                }
                $product->takeOffSale();
                $operation->update(['status' => 'cancelled', 'result' => ['taken_off_sale_by_update_id' => $update->id], 'executed_at' => now()]);

                return ['status' => 'completed', 'message' => "💤 Товар #{$productId} «{$product->title}» снят с продажи вместо удаления: на сайте его нет, "
                    .'карточка, фото и характеристики сохранены. Вернуть: кнопка под карточкой (/product '.$productId.') или «верни в продажу '.$productId.'».', 'notify' => true];
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
            $kept = app(StockUnits::class)->keptAfter($product);
            $product->delete();
            $operation->update(['status' => 'completed', 'result' => ['deleted_product_id' => $productId,
                'title' => $title, 'confirmed_by_update_id' => $update->id], 'executed_at' => now()]);

            return ['status' => 'completed', 'message' => "🗑 Товар #{$productId} «{$title}» удалён навсегда. Операция #{$operation->id}."
                // What was sold is history and stays; the operator should know why the books still name the product.
                .($kept > 0 ? " В учёте остались его единицы: {$kept} (проданные, списанные и описанные)." : ''), 'notify' => true];
        });
    }
}
