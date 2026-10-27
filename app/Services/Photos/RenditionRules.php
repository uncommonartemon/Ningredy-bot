<?php

namespace App\Services\Photos;

use App\Models\ImageRenditionRule;
use App\Models\ImageRenditionRuleOutcome;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Where a site keeps the larger rendition of a thumbnail, as data: the seed rules
 * shipped in database/data/rendition-rules.json, the rules the agent learned for one
 * site, and how each rule has done on each image host. A rule that keeps producing
 * addresses that are no larger stops on that host; a site's own learned rule that
 * keeps missing stops everywhere, and the agent learns the site again (operator,
 * 2026-10-27: "if a site changes something, can the agent change the rule itself?").
 */
final class RenditionRules
{
    /** @var null|array{at: float, rules: list<array<string, mixed>>, disabled: array<string, list<int>>} */
    private static ?array $loaded = null;

    /** @return list<array{id: ?int, host: ?string, find: string, flags: string, replace: string}> */
    public static function forHost(string $host): array
    {
        $host = strtolower($host);
        $data = self::load();
        $own = array_filter($data['rules'], fn (array $rule): bool => $rule['host'] !== null && self::hostMatches($host, $rule['host']));
        $global = array_filter($data['rules'], fn (array $rule): bool => $rule['host'] === null
            && ! in_array($rule['id'], $data['disabled'][$host] ?? [], true));

        return array_values([...$own, ...$global]);
    }

    /** @return array{rules: list<array<string, mixed>>, disabled: array<string, list<int>>} what the browser applies the same way */
    public static function forBrowser(): array
    {
        $data = self::load();

        return ['rules' => $data['rules'], 'disabled' => $data['disabled']];
    }

    /** The path rewritten by one rule, or null when the rule cannot run. */
    public static function apply(array $rule, string $path): ?string
    {
        $result = @preg_replace_callback(self::regex((string) $rule['find'], (string) ($rule['flags'] ?? '')),
            fn (array $match): string => (string) preg_replace_callback('/\{(\d)\}/',
                fn (array $group): string => (string) ($match[(int) $group[1]] ?? ''), (string) $rule['replace']),
            $path);

        return is_string($result) ? $result : null;
    }

    public static function valid(string $find, string $flags = ''): bool
    {
        return trim($find) !== '' && @preg_match(self::regex($find, $flags), '') !== false;
    }

    /**
     * One source's result for one rule on one host: a hit when any of its guesses gave a
     * larger usable frame. Misses in a row stop the rule there; a site's own rule stops
     * everywhere, so the agent learns the site again.
     */
    public static function record(?int $ruleId, string $host, bool $hit): void
    {
        $host = strtolower($host);
        if ($ruleId === null || $host === '') {
            return;
        }
        try {
            $outcome = ImageRenditionRuleOutcome::query()->firstOrCreate(['image_rendition_rule_id' => $ruleId, 'host' => $host]);
            if ($hit) {
                $outcome->update(['hits' => $outcome->hits + 1, 'consecutive_misses' => 0, 'last_hit_at' => now(), 'disabled_at' => null]);
            } else {
                $misses = $outcome->consecutive_misses + 1;
                $stop = $misses >= max(1, (int) config('product-images.rendition_probe.disable_after_misses', 6));
                $outcome->update(['misses' => $outcome->misses + 1, 'consecutive_misses' => $misses, 'last_miss_at' => now(),
                    'disabled_at' => $outcome->disabled_at ?? ($stop ? now() : null)]);
                if ($stop) {
                    ImageRenditionRule::query()->whereKey($ruleId)->whereNotNull('host')->update(['status' => 'disabled']);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
        self::forget();
    }

    /** A learned rule for one site; the caller has already seen it give larger frames. */
    public static function learn(string $host, string $find, string $flags, string $replace, ?string $note, string $exampleFrom, string $exampleTo): ?ImageRenditionRule
    {
        if (! self::valid($find, $flags)) {
            return null;
        }
        $rule = ImageRenditionRule::query()->create([
            'host' => strtolower($host), 'find' => $find, 'flags' => self::flags($flags), 'replace' => $replace,
            'origin' => 'agent', 'status' => 'active', 'position' => 5, 'note' => $note,
            'example_from' => mb_substr($exampleFrom, 0, 2048), 'example_to' => mb_substr($exampleTo, 0, 2048),
        ]);
        self::forget();

        return $rule;
    }

    /** @return list<array{id: ?int, host: ?string, find: string, flags: string, replace: string}> */
    public static function seeds(): array
    {
        $rules = json_decode((string) @file_get_contents(dirname(__DIR__, 3).'/database/data/rendition-rules.json'), true);

        return array_values(array_map(fn (array $rule): array => ['id' => null, 'host' => null, 'find' => (string) $rule['find'],
            'flags' => self::flags((string) ($rule['flags'] ?? '')), 'replace' => (string) $rule['replace']], is_array($rules) ? $rules : []));
    }

    public static function forget(): void
    {
        self::$loaded = null;
    }

    public static function hostMatches(string $host, string $ruleHost): bool
    {
        $ruleHost = strtolower(ltrim($ruleHost, '.'));

        return $host === $ruleHost || str_ends_with($host, '.'.$ruleHost);
    }

    /** @return array{at: float, rules: list<array<string, mixed>>, disabled: array<string, list<int>>} */
    private static function load(): array
    {
        if (self::$loaded !== null && microtime(true) - self::$loaded['at'] < 30) {
            return self::$loaded;
        }
        try {
            if (! Schema::hasTable('image_rendition_rules')) {
                throw new RuntimeException('The rendition rules are not migrated.');
            }
            $rules = ImageRenditionRule::query()->where('status', 'active')->orderBy('position')->orderBy('id')
                ->get(['id', 'host', 'find', 'flags', 'replace'])
                ->map(fn (ImageRenditionRule $rule): array => ['id' => $rule->id, 'host' => $rule->host, 'find' => $rule->find,
                    'flags' => self::flags((string) $rule->flags), 'replace' => $rule->replace])
                ->all();
            $disabled = ImageRenditionRuleOutcome::query()->whereNotNull('disabled_at')->get(['image_rendition_rule_id', 'host'])
                ->groupBy('host')->map(fn ($rows): array => $rows->pluck('image_rendition_rule_id')->map(fn ($id): int => (int) $id)->all())
                ->all();

            return self::$loaded = ['at' => microtime(true), 'rules' => array_values($rules), 'disabled' => $disabled];
        } catch (Throwable) {
            // Outside the application (a plain unit test) or before the migration: the seeds.
            return self::$loaded = ['at' => microtime(true), 'rules' => self::seeds(), 'disabled' => []];
        }
    }

    private static function regex(string $find, string $flags): string
    {
        return '~'.str_replace('~', '\~', $find).'~'.self::flags($flags);
    }

    /** Case-insensitive or not: the one flag PHP and the browser read the same way. */
    private static function flags(string $flags): string
    {
        return str_contains(strtolower($flags), 'i') ? 'i' : '';
    }
}
