<?php

namespace App\Services\ProductSearch\Research;

use Laravel\Ai\Streaming\Events\ProviderToolEvent;

/** Bounded executed-search metadata only; never copy arbitrary provider payloads. */
class ResearchWebSearchTrace
{
    public static function record(array $items, ProviderToolEvent $event): array
    {
        if ($event->type !== 'web_search_call' || $event->itemId === ''
            || (! isset($items[$event->itemId]) && count($items) >= 50)) {
            return $items;
        }

        $item = $items[$event->itemId] ?? ['id' => $event->itemId, 'type' => 'web_search_call'];
        $item['status'] = mb_substr($event->status, 0, 50);
        $action = $event->data['action'] ?? null;
        if (is_array($action)) {
            $safe = [];
            foreach (['type', 'query', 'url', 'pattern'] as $key) {
                if (is_string($action[$key] ?? null)) {
                    $safe[$key] = mb_substr($action[$key], 0, 2048);
                }
            }
            if (is_array($action['queries'] ?? null)) {
                $safe['queries'] = array_map(fn (string $query): string => mb_substr($query, 0, 2048),
                    array_slice(array_values(array_filter($action['queries'], 'is_string')), 0, 20));
            }
            // A later status-only event must not erase the executed action.
            $item['action'] = [...($item['action'] ?? []), ...$safe];
        }
        $items[$event->itemId] = $item;

        return $items;
    }
}
