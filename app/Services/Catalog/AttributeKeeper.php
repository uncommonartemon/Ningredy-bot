<?php

namespace App\Services\Catalog;

use App\Ai\Agents\AttributeDictionaryReviewAgent;
use App\Ai\Agents\AttributeKeeperAgent;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Models\CharacteristicQuestion;
use App\Services\Ai\AiSettings;
use App\Services\Products\AiAttributeGroups;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Agent;
use RuntimeException;
use Throwable;

/**
 * The model that keeps the characteristic dictionary (decision of 2026-11-13). Before a card is
 * approved it reads the characteristics the dictionary does not certainly know against the whole
 * dictionary: each is the same as an entry, or a new one with a name and a group. Characteristics
 * of that card that sit in no group get one in the same answer. A card the dictionary knows
 * entirely costs nothing. On demand it also reviews the whole dictionary for what to merge.
 */
class AttributeKeeper
{
    public function __construct(
        private readonly AttributeDictionary $dictionary,
        private readonly AiSettings $settings,
    ) {}

    /**
     * The keeper's verdicts on a card's characteristics; empty when the dictionary knows them all
     * or the model could not answer (the card is then written as before, nothing is lost).
     *
     * @param  list<array{index: int|string, key: string, name: string, value: string}>  $rows
     * @return array<string, array{decision: string, key: string, name: ?string, group: ?array, remember: bool}> by the row's key
     */
    public function settle(array $rows, ?string $product = null, ?Category $category = null, ?int $updateId = null): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            if (isset($candidates[$row['key']]) || $this->dictionary->known($row['key'], $row['name']) !== null) {
                continue;
            }
            $probably = $this->dictionary->named($row['name']) ?? $this->dictionary->named($row['key']);
            $candidates[$row['key']] = array_filter(['key' => $row['key'], 'name' => $row['name'],
                'value' => mb_substr($row['value'], 0, 200), 'probably' => $probably?->key], fn (mixed $value): bool => $value !== null);
        }
        if ($candidates === []) {
            return [];
        }
        // The model is asked anyway: the card's characteristics without a group come along.
        foreach ($rows as $row) {
            $definition = $this->dictionary->known($row['key'], $row['name']);
            if ($definition !== null && $definition->attribute_group_id === null && ! isset($candidates[$row['key']])) {
                $candidates[$row['key']] = ['key' => $row['key'], 'name' => $row['name'],
                    'value' => mb_substr($row['value'], 0, 200), 'known' => $definition->key];
            }
        }

        $answer = $this->ask(new AttributeKeeperAgent, [
            'product' => $product,
            'category' => $category === null ? null : $category->name.' ('.$category->slug.')',
            'card' => array_map(fn (array $row): string => $row['key'].' ('.$row['name'].') = '.mb_substr($row['value'], 0, 80), $rows),
            'candidates' => array_values($candidates),
            'dictionary' => $this->dictionary->entries(),
            'groups' => AttributeGroup::query()->orderBy('id')->get(['id', 'name'])->toArray(),
        ], 'attribute_dictionary', $updateId, (int) config('services.attribute_dictionary.timeout', 20), false);

        return $answer === null ? [] : $this->verdicts((array) ($answer['verdicts'] ?? []), $candidates);
    }

    /**
     * What the model proposes for the whole dictionary: pairs to merge (a key the code relies on
     * always stays, chains are followed to their end) and groups for entries without one.
     *
     * @return array{merges: list<array{from: string, into: string, reason: string}>, groups: list<array{key: string, group: array, reason: string}>}
     */
    public function review(): array
    {
        $declined = CharacteristicQuestion::declined();
        $answer = $this->ask(new AttributeDictionaryReviewAgent, [
            'dictionary' => $this->dictionary->entries(),
            'groups' => AttributeGroup::query()->orderBy('id')->get(['id', 'name'])->toArray(),
            'declined_by_the_operator' => CharacteristicQuestion::query()->where('status', 'declined')->get()
                ->map(fn (CharacteristicQuestion $question): array => ['kind' => $question->kind] + $question->payload)->all(),
        ], 'attribute_dictionary_review', null, (int) config('services.attribute_dictionary.audit_timeout', 300), true);
        $keys = $this->dictionary->all()->pluck('key')->flip();
        $protected = array_flip(AttributeDictionary::protectedKeys());
        $target = [];
        $notes = [];
        foreach ((array) ($answer['merges'] ?? []) as $merge) {
            [$from, $into] = [(string) ($merge['from'] ?? ''), (string) ($merge['into'] ?? '')];
            if (! $keys->has($from) || ! $keys->has($into) || $from === $into
                || isset($declined[CharacteristicQuestion::identity(CharacteristicQuestion::MERGE, ['from' => $from, 'into' => $into])])) {
                continue;
            }
            if (isset($protected[$from])) {
                if (isset($protected[$into])) {
                    continue;
                }
                [$from, $into] = [$into, $from];
            }
            $end = $into;
            while (isset($target[$end]) && $end !== $from) {
                $end = $target[$end];
            }
            if (isset($target[$from]) || $end === $from) {
                continue;
            }
            $target[$from] = $into;
            $notes[$from] = ['reason' => mb_substr((string) ($merge['reason'] ?? ''), 0, 300),
                'certainty' => ($merge['certainty'] ?? null) === 'sure' ? 'sure' : 'doubtful'];
        }
        $merges = [];
        foreach ($target as $from => $into) {
            $certainty = $notes[$from]['certainty'];
            while (isset($target[$into])) {
                // A chain is as sure as its least sure link.
                $certainty = $notes[$into]['certainty'] === 'sure' ? $certainty : 'doubtful';
                $into = $target[$into];
            }
            $reason = $notes[$from]['reason'];
            // Values that differ on one product say these were two properties: a person decides.
            if ($certainty === 'sure' && ($differ = $this->dictionary->conflicts((string) $from, (string) $into)) > 0) {
                $certainty = 'doubtful';
                $reason = trim($reason.' '.__('У :count товаров есть обе, и значения разные.', ['count' => $differ]));
            }
            $merges[] = ['from' => (string) $from, 'into' => (string) $into, 'certainty' => $certainty, 'reason' => $reason];
        }
        $groups = [];
        foreach ((array) ($answer['groups'] ?? []) as $proposal) {
            $definition = $this->dictionary->all()->firstWhere('key', (string) ($proposal['key'] ?? ''));
            $group = AiAttributeGroups::normalize($proposal['group'] ?? null);
            if ($definition === null || $definition->attribute_group_id !== null || $group === null || isset($target[$definition->key])
                || isset($declined[CharacteristicQuestion::identity(CharacteristicQuestion::GROUP, ['key' => $definition->key, 'group' => $group])])) {
                continue;
            }
            $groups[$definition->key] = ['key' => $definition->key, 'group' => $group,
                'certainty' => ($proposal['certainty'] ?? null) === 'sure' ? 'sure' : 'doubtful',
                'reason' => mb_substr((string) ($proposal['reason'] ?? ''), 0, 300)];
        }

        return ['merges' => $merges, 'groups' => array_values($groups)];
    }

    /**
     * Applies the proposals the operator kept: each merge, then each group for an entry that still
     * has none (a group someone set in the meantime stays).
     *
     * @param  list<array{from: string, into: string}>  $merges
     * @param  list<array{key: string, group: array}>  $groups
     * @return array{merged: list<array{from: string, into: string, moved: int, dropped: int, swapped: bool}>, grouped: int, skipped: list<string>}
     */
    public function apply(array $merges, array $groups): array
    {
        $merged = [];
        $skipped = [];
        foreach ($merges as $merge) {
            $from = AttributeDefinition::query()->where('key', (string) ($merge['from'] ?? ''))->first();
            $into = AttributeDefinition::query()->where('key', (string) ($merge['into'] ?? ''))->first();
            if ($from === null || $into === null) {
                $skipped[] = ($merge['from'] ?? '?').' → '.($merge['into'] ?? '?').': '.__('характеристики уже нет');

                continue;
            }
            try {
                $merged[] = $this->dictionary->merge($from, $into);
            } catch (RuntimeException $exception) {
                $skipped[] = $from->key.' → '.$into->key.': '.$exception->getMessage();
            }
        }
        $grouped = 0;
        foreach ($groups as $proposal) {
            $definition = AttributeDefinition::query()->where('key', (string) ($proposal['key'] ?? ''))->first();
            if ($definition === null || $definition->attribute_group_id !== null) {
                continue;
            }
            $group = DB::transaction(fn (): ?AttributeGroup => AiAttributeGroups::resolve($proposal['group'] ?? null));
            if ($group !== null) {
                $definition->update(['attribute_group_id' => $group->id]);
                $grouped++;
            }
        }

        return ['merged' => $merged, 'grouped' => $grouped, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidates  by key
     * @return array<string, array{decision: string, key: string, name: ?string, group: ?array, remember: bool}>
     */
    private function verdicts(array $answers, array $candidates): array
    {
        $verdicts = [];
        foreach ($answers as $answer) {
            $raw = (string) ($answer['candidate'] ?? '');
            if (! isset($candidates[$raw]) || isset($verdicts[$raw])) {
                continue;
            }
            $candidate = $candidates[$raw];
            $group = AiAttributeGroups::normalize($answer['group'] ?? null);
            if (isset($candidate['known'])) {
                $verdicts[$raw] = ['decision' => 'known', 'key' => $candidate['known'], 'name' => null, 'group' => $group, 'remember' => false];

                continue;
            }
            $key = (string) ($answer['key'] ?? '');
            $remember = (bool) ($answer['remember'] ?? false);
            $existing = $this->dictionary->all()->firstWhere('key', $key);
            if ($existing !== null) {
                $verdicts[$raw] = ['decision' => 'same', 'key' => $existing->key, 'name' => null, 'group' => $group, 'remember' => $remember];

                continue;
            }
            if (($answer['decision'] ?? null) !== 'new') {
                // "Same" as an entry that does not exist is no verdict: the card goes on as before.
                continue;
            }
            $name = trim((string) ($answer['name'] ?? ''));
            $verdicts[$raw] = [
                'decision' => 'new',
                'key' => preg_match('/^[a-z0-9_]{1,100}$/', $key) === 1 ? $key : $raw,
                'name' => mb_substr($name !== '' ? $name : (string) $candidate['name'], 0, 255),
                'group' => $group,
                'remember' => $remember,
            ];
        }

        return $verdicts;
    }

    private function ask(Agent $agent, array $payload, string $stage, ?int $updateId, int $timeout, bool $rethrow): ?array
    {
        $prompt = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $run = AiRun::query()->create([
            'telegram_update_id' => $updateId,
            'provider' => $this->settings->providerFor('attribute_dictionary'),
            'model' => $this->settings->modelFor('attribute_dictionary'),
            'status' => 'running', 'prompt' => mb_substr((string) $prompt, 0, 60000), 'started_at' => now(),
            'activity' => ['stage' => $stage],
        ]);
        try {
            $response = $agent->prompt((string) $prompt, provider: $run->provider, model: $run->model, timeout: $timeout);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);
            if ($rethrow) {
                throw $exception;
            }
            report($exception);

            return null;
        }
        $run->update(['status' => 'completed', 'usage' => $response->usage->toArray(), 'invocation_id' => $response->invocationId,
            'response' => $response->toArray(), 'completed_at' => now()]);

        return $response->toArray();
    }
}
