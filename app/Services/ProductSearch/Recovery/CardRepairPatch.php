<?php

namespace App\Services\ProductSearch\Recovery;

use App\Models\ProductDraft;
use App\Services\Products\ProductInputContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A rejected proposal is an immutable patch base, never the trusted draft baseline. */
final class CardRepairPatch
{
    private const VERSION = 1;

    // What the agent sees: allowed_paths + current_values (version 1 called
    // the old values allowed_values, which models read as an enum).
    private const AGENT_CONTRACT_VERSION = 2;

    private const FACT_FIELDS = ['value', 'evidence', 'reason', 'source_kind', 'source_ref', 'source_url', 'source_excerpt', 'considered_source_urls', 'considered_source_refs'];

    public function prepare(ProductDraft $draft, array $source, ?int $updateId, array $feedback): ?array
    {
        $context = $this->contextHash($draft, $source, $updateId);
        $prior = $feedback['repair_context']['patch_repair'] ?? null;
        if (is_array($prior) && ($prior['context_hash'] ?? null) === $context
            && ($prior['base_hash'] ?? null) === $this->hash([$context, $prior['base'] ?? [], $prior['paths'] ?? []])) {
            return $prior;
        }
        $base = $feedback['repair_context']['previous_candidate'] ?? null;
        if (! is_array($base) || ! is_array($base['specifications'] ?? null)
            || ! array_key_exists('title', $base) || ! array_key_exists('model', $base) || ! array_key_exists('description', $base)
            || ($feedback['reason'] ?? null) === 'identity_check_unavailable') {
            return null;
        }
        $indices = [];
        foreach ($base['specifications'] as $index => $fact) {
            if (! is_array($fact) || ! is_string($fact['key'] ?? null) || isset($indices[$fact['key']])) {
                return null;
            }
            $indices[$fact['key']] = $index;
        }
        $paths = [];
        $addFact = function (string $key, array $fields) use (&$paths, $indices): void {
            if (! array_key_exists($key, $indices)) {
                return;
            }
            foreach ($fields as $field) {
                $paths['specifications.'.$key.'.'.$field] = ['specifications', $indices[$key], ...explode('.', $field)];
            }
        };
        foreach ($feedback['repair_context']['validation_errors'] ?? [] as $field => $messages) {
            if (is_string($field) && preg_match('/^specifications\.(\d+)\.(.+)$/', $field, $match)) {
                $key = $base['specifications'][(int) $match[1]]['key'] ?? null;
                if (is_string($key) && in_array(explode('.', $match[2])[0], [...self::FACT_FIELDS, 'name', 'group'], true)) {
                    $addFact($key, [$match[2]]);
                }
            } elseif (is_string($field) && in_array($field, ['title', 'model', 'color', 'description', 'summary'], true)) {
                $paths[$field] = [$field];
            }
            foreach ((array) $messages as $message) {
                if (! is_string($message)) {
                    continue;
                }
                foreach ($indices as $key => $index) {
                    if (! str_starts_with($message, $key.':')) {
                        continue;
                    }
                    $fields = match (true) {
                        str_contains($message, 'considered_source_urls') => ['reason', 'considered_source_urls', 'considered_source_refs'],
                        str_contains($message, 'confirmed but changed'), str_contains($message, 'corrected but unchanged') => ['evidence', 'reason'],
                        str_contains($message, 'corrected value requires a reason') => ['reason'],
                        default => self::FACT_FIELDS,
                    };
                    $addFact($key, $fields);
                }
            }
        }
        $targets = $feedback['repair_context']['repair_targets'] ?? [];
        foreach ($targets['specification_keys'] ?? [] as $key) {
            if (is_string($key)) {
                $addFact($key, self::FACT_FIELDS);
            }
        }
        foreach ($targets['paths'] ?? [] as $path) {
            if (in_array($path, ['model', 'color'], true)) {
                $paths[$path] = [$path];
            }
        }
        if ($paths === []) {
            return null;
        }

        return ['version' => self::VERSION, 'context_hash' => $context, 'base' => $base,
            'paths' => $paths, 'base_hash' => $this->hash([$context, $base, $paths]),
            'repair_targets' => $targets,
            'citation_obligations' => $feedback['repair_context']['citation_obligations'] ?? []];
    }

    public function forAgent(array $repair): array
    {
        $values = [];
        $facts = [];
        foreach ($repair['paths'] as $path => $segments) {
            $values[$path] = data_get($repair['base'], implode('.', $segments));
            if ($segments[0] === 'specifications') {
                $fact = $repair['base']['specifications'][$segments[1]];
                $facts[$fact['key']] = $fact;
            }
        }

        return ['version' => self::AGENT_CONTRACT_VERSION, 'base_hash' => $repair['base_hash'],
            'allowed_paths' => array_keys($values), 'current_values' => $values,
            'citation_obligations' => $repair['citation_obligations'] ?? [],
            'affected_candidate_facts' => $facts,
            'conditional_paths' => $this->dependencies($repair), 'repair_targets' => $repair['repair_targets']];
    }

    public function assertCurrent(ProductDraft $draft, array $source, ?int $updateId, array $repair): void
    {
        if (! hash_equals($repair['context_hash'], $this->contextHash($draft, $source, $updateId))) {
            $this->reject('The draft generation or baseline changed while repairing; the old patch cannot be applied.');
        }
    }

    /** Supervisor may widen fields of an already affected fact, never unrelated product data. */
    public function expandForRecovery(array $repair, array $requestedPaths): array
    {
        $affected = [];
        foreach ($repair['paths'] as $segments) {
            if ($segments[0] === 'specifications') {
                $affected[$repair['base']['specifications'][$segments[1]]['key']] = $segments[1];
            }
        }
        foreach ($requestedPaths as $path) {
            $parts = explode('.', $path);
            if (count($parts) !== 3 || $parts[0] !== 'specifications'
                || ! array_key_exists($parts[1], $affected) || ! in_array($parts[2], self::FACT_FIELDS, true)) {
                $this->reject('Recovery cannot expand an unrelated field: '.$path);
            }
            $repair['paths'][$path] = ['specifications', $affected[$parts[1]], $parts[2]];
        }
        $repair['base_hash'] = $this->hash([$repair['context_hash'], $repair['base'], $repair['paths']]);

        return $repair;
    }

    public function apply(array $repair, array $patch): array
    {
        Validator::make(['patch' => $patch], [
            'patch' => ['required', 'array:base_hash,decision,changes,unresolved_fields,summary'],
            'patch.base_hash' => ['required', 'string'], 'patch.decision' => ['required', 'in:patch,not_ready'],
            'patch.changes' => ['present', 'array', 'max:100'],
            'patch.changes.*' => ['array:path,value_json'], 'patch.changes.*.path' => ['required', 'string', 'distinct'],
            'patch.changes.*.value_json' => ['required', 'string', 'json', 'max:12000'],
            'patch.unresolved_fields' => ['present', 'array', 'max:20'],
            'patch.unresolved_fields.*' => ['array:key,status,reason'],
            'patch.unresolved_fields.*.key' => ['required', 'string'],
            'patch.unresolved_fields.*.status' => ['required', 'in:unknown,conflicting'],
            'patch.unresolved_fields.*.reason' => ['required', 'string', 'max:300'],
            'patch.summary' => ['required', 'string', 'max:500'],
        ])->validate();
        if (! hash_equals($repair['base_hash'], $patch['base_hash'])) {
            $this->reject('Patch base_hash is stale; use the exact supplied base and scope.');
        }
        $candidate = $repair['base'];
        $dependencies = $this->dependencies($repair);
        $dependentChanges = [];
        foreach ($patch['changes'] as $change) {
            $path = $change['path'];
            $value = json_decode($change['value_json'], true, flags: JSON_THROW_ON_ERROR);
            if (isset($repair['paths'][$path])) {
                Arr::set($candidate, implode('.', $repair['paths'][$path]), $value);
            } elseif (isset($dependencies[$path])) {
                $dependentChanges[$path] = $value;
            } else {
                $this->reject('Path outside the supplied repair scope: '.$path);
            }
        }
        // Translated values must be available before checking their prose dependency.
        uksort($dependentChanges, fn (string $a, string $b): int => (($dependencies[$a]['kind'] ?? null) === 'prose') <=> (($dependencies[$b]['kind'] ?? null) === 'prose'));
        foreach ($dependentChanges as $path => $value) {
            $dependency = $dependencies[$path];
            $changed = array_filter($dependency['keys'], fn (string $key): bool => $this->factChanged($repair['base'], $candidate, $key));
            if ($changed === []) {
                $this->reject('Metadata-only repair cannot change dependent text: '.$path);
            }
            if (isset($dependency['specification_key']) && (! is_array($value) || ($value['key'] ?? null) !== $dependency['specification_key']
                || array_diff(array_keys($value), ['key', 'name', 'value']) !== [])) {
                $this->reject('A dependent translation must keep its exact specification key.');
            }
            if (($dependency['kind'] ?? null) === 'prose') {
                // A local replacement preserves unrelated prose byte-for-byte.
                $text = data_get($candidate, implode('.', $dependency['segments']));
                if (! is_string($text) || ! is_array($value) || array_diff(array_keys($value), ['find', 'replace']) !== []
                    || ! is_string($value['find'] ?? null) || $value['find'] === '' || ! is_string($value['replace'] ?? null)
                    || substr_count($text, $value['find']) !== 1
                    || ! $this->localProseChange($repair['base'], $candidate, $path, $changed, $text, $value)) {
                    $this->reject('Dependent prose needs one exact {find,replace} fragment from the retained description.');
                }
                $value = str_replace($value['find'], $value['replace'], $text);
            }
            Arr::set($candidate, implode('.', $dependency['segments']), $value);
        }
        if ($patch['decision'] === 'not_ready') {
            if ($patch['changes'] !== [] || $patch['unresolved_fields'] === []) {
                $this->reject('not_ready preserves the base and must name its unresolved fields.');
            }
            $known = [];
            foreach ($repair['paths'] as $segments) {
                $known[] = $segments[0] === 'specifications' ? $candidate['specifications'][$segments[1]]['key'] : $segments[0];
            }
            foreach ($patch['unresolved_fields'] as $field) {
                if (! in_array($field['key'], $known, true)) {
                    $this->reject('Unknown unresolved field: '.$field['key']);
                }
            }
            $candidate['overall_status'] = 'not_ready';
            $candidate['recovery_action'] = 'retry_current_source';
            $candidate['unresolved_fields'] = $patch['unresolved_fields'];
            $candidate['summary'] = $patch['summary'];
        } elseif ($patch['changes'] === [] || $patch['unresolved_fields'] !== []) {
            $this->reject('A repair patch must contain changes and no unhandled blockers.');
        }

        return $candidate;
    }

    private function dependencies(array $repair): array
    {
        $keys = [];
        foreach ($repair['paths'] as $segments) {
            if (($segments[0] ?? null) === 'specifications' && in_array($segments[2] ?? null, ['value', 'evidence'], true)) {
                $keys[] = $repair['base']['specifications'][$segments[1]]['key'];
            }
        }
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return [];
        }
        $result = [];
        $translated = $repair['base']['translations']['cs']['specifications'] ?? [];
        foreach ($keys as $key) {
            $index = array_search($key, array_column($translated, 'key'), true);
            if ($index === false) {
                $index = count($translated);
                $translated[] = ['key' => $key];
            }
            $result['translations.cs.specifications.'.$key] = ['keys' => [$key], 'specification_key' => $key,
                'segments' => ['translations', 'cs', 'specifications', $index]];
        }
        foreach (['description', 'translations.cs.description'] as $path) {
            $result[$path] = ['keys' => $keys, 'kind' => 'prose', 'segments' => explode('.', $path),
                'current_text' => data_get($repair['base'], $path)];
        }

        return $result;
    }

    private function factChanged(array $base, array $candidate, string $key): bool
    {
        $old = collect($base['specifications'])->firstWhere('key', $key);
        $new = collect($candidate['specifications'])->firstWhere('key', $key);

        return ($old['value'] ?? null) !== ($new['value'] ?? null)
            || (($old['evidence'] ?? null) === 'dropped') !== (($new['evidence'] ?? null) === 'dropped');
    }

    private function localProseChange(array $base, array $candidate, string $path, array $keys, string $text, array $change): bool
    {
        $translated = $path === 'translations.cs.description';
        $factsPath = $translated ? 'translations.cs.specifications' : 'specifications';
        $oldFacts = collect(data_get($base, $factsPath, []))->keyBy('key');
        $newFacts = collect(data_get($candidate, $factsPath, []))->keyBy('key');
        foreach ($keys as $key) {
            $old = $oldFacts->get($key)['value'] ?? null;
            $new = $newFacts->get($key)['value'] ?? null;
            $dropped = collect($candidate['specifications'])->firstWhere('key', $key)['evidence'] === 'dropped';
            if (! is_string($old) || $old === '' || preg_match('/^[\d\s.,+-]+$/u', $old)
                || preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($old, '/').'(?![\p{L}\p{N}_])/u', $change['find']) !== 1
                || preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($old, '/').'(?![\p{L}\p{N}_])/u', $text) !== 1
                || $oldFacts->contains(fn (array $fact, string $other): bool => $other !== $key
                    && is_string($fact['value'] ?? null) && str_contains($fact['value'], $old))) {
                continue;
            }
            if (! $dropped && $old !== $new && $change['find'] === $old && $change['replace'] === $new) {
                return true;
            }
            // Dropping a fact may remove one complete sentence/line, never rewrite
            // arbitrary prose or delete a sentence containing another known fact.
            if ($dropped && $change['replace'] === '' && str_contains($change['find'], $old)
                && in_array($change['find'], preg_split('/(?<=[.!?])\s+|\R/u', $text), true)
                && ! $oldFacts->contains(fn (array $fact, string $other): bool => $other !== $key
                    && is_string($fact['value'] ?? null) && $fact['value'] !== '' && str_contains($change['find'], $fact['value']))) {
                return true;
            }
        }

        return false;
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function contextHash(ProductDraft $draft, array $source, ?int $updateId): string
    {
        return $this->hash([self::VERSION, $draft->id, $draft->telegram_update_id, $updateId,
            $source['url'] ?? null, $draft->brand, $draft->model, $draft->color, $draft->condition ?? 'new',
            $draft->status ?? 'pending_review', $draft->product_type, $draft->category,
            $draft->title, $draft->description, $draft->translations, $draft->specifications,
            $draft->exists ? ProductInputContext::forDraft($draft) : null,
            $source['_photo_applicability']['target_fingerprint'] ?? null]);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['repair_patch' => [$message]]);
    }
}
