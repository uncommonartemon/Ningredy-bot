<?php

namespace App\Services\Products;

use Illuminate\Support\Facades\Cache;

/**
 * What a shop has recently told us about itself, remembered per host.
 *
 * Three separate pieces of knowledge used to live in three places, none of
 * which could see the others: a robot check slowed the next visit down, an
 * HTTP/2 downgrade was forgotten the moment the call returned, and a refusal
 * was not recorded at all - so a host that answered nothing was visited eight
 * more times at the ordinary pace.
 *
 * Live on 2026-09-06: acer refused every one of eight visits inside three
 * minutes; bestbuy returned 403 five times. Both were treated as eight and
 * five independent accidents. They were one answer, repeated.
 *
 * Nothing here is about a particular shop. A host earns its own reputation
 * from what it actually returned, and loses it as soon as it answers again.
 */
class HostReputation
{
    /**
     * A page the browser was served that says we are not welcome - a robot
     * check, a security wall, a 403.
     */
    public const REFUSAL_ACCESS_GATE = 'access_gate';

    /**
     * The connection is accepted and then nothing arrives. Distinct from a
     * gate because there is no page to read - it is the shape a tarpit has,
     * and it costs a full timeout every time rather than failing fast.
     */
    public const REFUSAL_SILENCE = 'silence';

    public function noteRefusal(string $url, string $reason): int
    {
        $host = $this->host($url);

        if ($host === '') {
            return 0;
        }

        $current = $this->refusal($url);
        $count = (int) ($current['count'] ?? 0) + 1;

        Cache::put($this->refusalKey($host), [
            'count' => $count,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ], now()->addMinutes($this->memoryMinutes()));

        return $count;
    }

    /**
     * The host answered. Whatever it was refusing us for is over, so the
     * record goes rather than ages out - otherwise a single bad minute would
     * keep a working shop at the slow pace for the rest of the window.
     */
    public function noteAcceptance(string $url): void
    {
        $host = $this->host($url);

        if ($host !== '') {
            Cache::forget($this->refusalKey($host));
        }
    }

    /**
     * Whether this host has refused often enough that the next visit is worth
     * skipping entirely.
     *
     * One refusal is not enough: shops have bad minutes, and writing a domain
     * off for a transient failure loses sources that would have worked. Two is
     * an answer.
     */
    public function isRefusing(string $url): bool
    {
        return (int) ($this->refusal($url)['count'] ?? 0) >= $this->threshold();
    }

    /**
     * Whether this host has pushed back at all recently - the weaker signal,
     * enough to slow visits down but not to stop them.
     */
    public function isChallenged(string $url): bool
    {
        return $this->refusal($url) !== null;
    }

    /** @return array{count: int, reason: string, at: string}|null */
    public function refusal(string $url): ?array
    {
        $host = $this->host($url);

        if ($host === '') {
            return null;
        }

        $stored = Cache::get($this->refusalKey($host));

        return is_array($stored) && isset($stored['count']) ? $stored : null;
    }

    /**
     * Whether this host has already been seen breaking HTTP/2.
     *
     * The downgrade used to live inside one call and die with it, so every
     * later visit in the same search paid the broken stream again before
     * retrying. A server's HTTP/2 stack is a property of the server, not of
     * the request, and is worth remembering for longer than a search.
     */
    public function prefersHttp11(string $url): bool
    {
        $host = $this->host($url);

        return $host !== '' && Cache::get($this->http11Key($host)) !== null;
    }

    public function noteHttp11Downgrade(string $url): void
    {
        $host = $this->host($url);

        if ($host !== '') {
            Cache::put(
                $this->http11Key($host),
                now()->toIso8601String(),
                now()->addHours((int) config('product-images.browser_fallback.http11_memory_hours', 24)),
            );
        }
    }

    public function forget(string $url): void
    {
        $host = $this->host($url);

        if ($host !== '') {
            Cache::forget($this->refusalKey($host));
            Cache::forget($this->http11Key($host));
        }
    }

    private function threshold(): int
    {
        return max(1, (int) config('product-images.browser_fallback.refusal_threshold', 2));
    }

    private function memoryMinutes(): int
    {
        return max(1, (int) config('product-images.browser_fallback.refusal_memory_minutes', 20));
    }

    private function refusalKey(string $host): string
    {
        return 'host-reputation:refusals:'.$host;
    }

    private function http11Key(string $host): string
    {
        return 'host-reputation:http11:'.$host;
    }

    private function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }
}
