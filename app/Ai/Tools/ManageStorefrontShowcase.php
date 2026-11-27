<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\Catalog\StorefrontShowcase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * The start page's showcase from the chat, through the same setting as the admin page
 * «Настройки → Витрина» (decision of 2026-11-27).
 */
class ManageStorefrontShowcase implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'The products the site\'s start page shows first («Витрина», «товары на главной»), at most '.StorefrontShowcase::LIMIT.', in order: '
            .'the first three are the plates on the first screen, all of them lead the row of new products; one taken off sale or without '
            .'a photo is skipped by the site until it can be shown; with none chosen the page takes the newest products with photos. '
            .'action=show lists them; action=set replaces the whole list with product_ids in that order (an empty list clears it); '
            .'action=add puts product_ids at position (1-based, the end when omitted); action=remove takes product_ids out.';
    }

    public function handle(Request $request): Stringable|string
    {
        $action = (string) $request->string('action');
        $ids = array_values(array_map('intval', (array) ($request['product_ids'] ?? [])));
        try {
            if ($action === 'show') {
                return $this->json(['ok' => true, ...$this->shown()]);
            }
            $current = StorefrontShowcase::ids();
            $next = match ($action) {
                'set' => $ids,
                'add' => $this->inserted($current, $ids, $request['position'] ?? null),
                'remove' => array_values(array_diff($current, $ids)),
                default => null,
            };
            if ($next === null) {
                return $this->json(['ok' => false, 'error' => 'Unknown action.']);
            }

            return $this->json($this->recordOperation($this->update, class_basename(self::class), $action.'_showcase',
                ['product_ids' => $next], function () use ($current, $next): array {
                    StorefrontShowcase::save($next);

                    return ['before' => $current, ...$this->shown()];
                }));
        } catch (Throwable $exception) {
            return $this->json(['ok' => false, 'error' => mb_substr($exception->getMessage(), 0, 800)]);
        }
    }

    /** @return array{products: list<array<string, mixed>>, limit: int} */
    private function shown(): array
    {
        $ids = StorefrontShowcase::ids();
        $products = Product::query()->whereIn('id', $ids)->withCount(['media as catalog_photos' => fn ($media) => $media->where('type', 'image')
            ->whereIn('verification_status', ['verified', 'source_verified', 'manual'])])->get(['id', 'title', 'status', 'is_active'])->keyBy('id');

        return ['products' => collect($ids)->values()->map(fn (int $id, int $index): array => [
            'position' => $index + 1, 'product_id' => $id, 'title' => $products[$id]->title,
            'on_sale' => $products[$id]->isOnSale(), 'has_photo' => $products[$id]->catalog_photos > 0,
            'shown_on_site' => $products[$id]->isOnSale() && $products[$id]->catalog_photos > 0,
        ])->all(), 'limit' => StorefrontShowcase::LIMIT];
    }

    /**
     * @param  list<int>  $current
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function inserted(array $current, array $ids, mixed $position): array
    {
        $rest = array_values(array_diff($current, $ids));
        $at = is_numeric($position) ? max(0, min(count($rest), (int) $position - 1)) : count($rest);
        array_splice($rest, $at, 0, $ids);

        return $rest;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['show', 'set', 'add', 'remove'])->required(),
            'product_ids' => $schema->array()->max(StorefrontShowcase::LIMIT)->items($schema->integer())->description('For set, add and remove: catalog product ids.'),
            'position' => $schema->integer()->min(1)->description('For add: the place of the first added product, 1-based.'),
        ];
    }
}
