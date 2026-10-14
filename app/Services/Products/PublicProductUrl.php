<?php

namespace App\Services\Products;

use RuntimeException;

/** Resolves an untrusted URL once; the caller must connect to these exact IPs. */
class PublicProductUrl
{
    /** @return array{host:string,port:int,addresses:array<int,string>} */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Blocked non-public product source URL.');
        }
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || str_ends_with($host, '.')
            || (! filter_var($host, FILTER_VALIDATE_IP) && preg_match('/^(?:[0-9.]+|0x[0-9a-f]+)$/', $host))
            || preg_match('/[^a-z0-9.:-]/', $host)) {
            throw new RuntimeException('Blocked non-public product source URL.');
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->lookup($host);
        if ($addresses === [] || array_filter($addresses, fn (string $ip): bool => ! self::isPublicIp($ip)) !== []) {
            throw new RuntimeException('Blocked non-public product source URL.');
        }

        return ['host' => $host, 'port' => $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80),
            'addresses' => array_values(array_unique($addresses))];
    }

    /** @return array<int,string> */
    protected function lookup(string $host): array
    {
        $v4 = @gethostbynamel($host) ?: [];
        $v6 = @dns_get_record($host, DNS_AAAA) ?: [];

        return [...$v4, ...array_values(array_filter(array_column($v6, 'ipv6'), 'is_string'))];
    }

    public static function isPublicIp(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $packed = inet_pton($address);
        if (strlen($packed) === 4) {
            [$a, $b, $c] = array_values(unpack('C*', $packed));

            return ! (($a === 100 && $b >= 64 && $b <= 127)
                || ($a === 192 && $b === 0 && in_array($c, [0, 2], true))
                || ($a === 192 && $b === 88 && $c === 99) || ($a === 198 && in_array($b, [18, 19], true))
                || ($a === 198 && $b === 51 && $c === 100) || ($a === 203 && $b === 0 && $c === 113)
                || $a >= 224);
        }

        // Global unicast only: excludes mapped IPv4, local, link-local, multicast
        // and translation prefixes rather than allowing an alternate private-IP form.
        $hex = bin2hex($packed);

        return (ord($packed[0]) & 0xE0) === 0x20
            && ! str_starts_with($hex, '20010db8') && ! str_starts_with($hex, '20010000')
            && ! str_starts_with($hex, '2002') && ! str_starts_with($hex, '3fff');
    }
}
