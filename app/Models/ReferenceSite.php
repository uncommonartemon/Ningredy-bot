<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A site the operator trusts for a kind of product ("SSD and HDD", "Intel
 * processors"): what to take from it (facts, photos), how the bot finds a code
 * there, and whether the bot may open it at all. A site that refuses bots is
 * kept for a person: the bot only hands its link to the operator.
 * Placeholders in the search and the link: {code}, {model}, {brand}.
 */
class ReferenceSite extends Model
{
    /** The bot searches and reads the site itself. */
    public const string ACCESS_BOT = 'bot';

    /** The site refuses bots (bot check, robots.txt): only a person opens it, the bot gives the link. */
    public const string ACCESS_PERSON = 'person';

    public const array ACCESS = [self::ACCESS_BOT, self::ACCESS_PERSON];

    protected $fillable = [
        'domain', 'applies_to', 'use_for_facts', 'use_for_photos', 'access', 'search_hint', 'link_template',
        'instructions', 'sort_order', 'enabled', 'hits', 'misses', 'last_used_at', 'last_result',
    ];

    protected function casts(): array
    {
        return [
            'use_for_facts' => 'boolean',
            'use_for_photos' => 'boolean',
            'enabled' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /** The bare host, whatever was typed: "https://www.Example.com/x" → "example.com". */
    public static function normalizeDomain(?string $domain): string
    {
        $domain = mb_strtolower(trim((string) $domain));
        $host = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain, PHP_URL_HOST);

        return preg_replace('/^www\./', '', is_string($host) ? $host : $domain) ?? '';
    }

    protected static function booted(): void
    {
        // The chat and the admin both write rows; the address is stored one way.
        static::saving(function (self $site): void {
            $site->domain = self::normalizeDomain($site->domain);
            $site->access = in_array($site->access, self::ACCESS, true) ? $site->access : self::ACCESS_BOT;
        });
    }
}
