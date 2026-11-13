<?php

namespace App\Services\Support;

/**
 * The PHP memory a worker may use: its limit, what is still free, and raising it for the image
 * work. On the colleague's bot (request #343, 2026-11-13) a search worker died at 512 MB while
 * decoding enlarged pictures; the guardian put the search back, three minutes were lost.
 */
final class WorkerMemory
{
    /** The limit in bytes; PHP_INT_MAX when unlimited. */
    public static function limit(): int
    {
        return self::bytes((string) ini_get('memory_limit'));
    }

    /** What the worker can still allocate before PHP stops it. */
    public static function free(): int
    {
        $limit = self::limit();

        return $limit === PHP_INT_MAX ? PHP_INT_MAX : max(0, $limit - memory_get_usage(true));
    }

    /** Raises the limit to at least this; never lowers it and leaves an unlimited one alone. */
    public static function raise(string $to): void
    {
        $wanted = self::bytes($to);
        if ($wanted !== PHP_INT_MAX && $wanted > self::limit()) {
            ini_set('memory_limit', $to);
        }
    }

    private static function bytes(string $raw): int
    {
        $raw = strtolower(trim($raw));
        if ($raw === '' || $raw === '-1') {
            return PHP_INT_MAX;
        }
        $bytes = (int) $raw * match (substr($raw, -1)) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };

        return $bytes > 0 ? $bytes : PHP_INT_MAX;
    }
}
