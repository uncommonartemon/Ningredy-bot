<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Stringable;

/** Uses the assembler's evidence tools, but can only propose a scoped patch. */
#[MaxTokens(self::MAX_OUTPUT_TOKENS)]
#[MaxSteps(3)]
class ProductSpecificationRepairAgent extends ProductSpecificationReconciliationAgent
{
    public function instructions(): Stringable|string
    {
        return parent::instructions().<<<'PROMPT'

            TARGETED REPAIR OUTPUT CONTRACT (replaces the full-card output instruction above):
            Return only base_hash, decision, changes, unresolved_fields and summary. Never return a new card.
            Copy repair.base_hash exactly. Each change has an exact path from repair.allowed_values and
            value_json: the JSON-encoded replacement value (a quoted JSON string, array, object or null).
            The original current_specifications remain the comparison baseline. repair.allowed_values
            are from the rejected candidate; affected_candidate_facts are read-only context including
            their current values and citations. Repair only listed paths. Keep other work unchanged.
            Do not claim to have considered a source by merely adding its URL: read retained/tool evidence
            and explain applicability. Existing evidence, identity and photo checks all run again after merge.
            Conditional translation/prose paths are permitted ONLY when this patch changes a permitted
            specification's actual value or drops it. Status/citation-only repairs cannot rewrite them.
            For a Czech specification translation return only that key/name/value object. For description
            or translations.cs.description, value_json is {"find":"exact old fragment","replace":"corrected fragment"}:
            the old fragment must occur exactly once and equal the old fact value; replace it with the
            new fact value (use the matching Czech translated value for Czech prose). For a dropped fact,
            you may remove one complete sentence/line containing its old value and no other fact value.
            Bare numeric counts cannot identify a prose claim safely; do not edit prose through them.
            Never replace unrelated prose or translations. Do not invent facts to satisfy a guard.
            If the scope or available tools cannot resolve the blocker (including required photo reassessment),
            return decision=not_ready, changes=[], existing unresolved keys with reasons and an honest summary.
            Otherwise return decision=patch, the minimal changes, and unresolved_fields=[].
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'base_hash' => $schema->string()->required(),
            'decision' => $schema->string()->enum(['patch', 'not_ready'])->required(),
            'changes' => $schema->array()->max(100)->items($schema->object([
                'path' => $schema->string()->required(),
                'value_json' => $schema->string()->max(12000)->required(),
            ])->withoutAdditionalProperties())->required(),
            'unresolved_fields' => $schema->array()->max(20)->items($schema->object([
                'key' => $schema->string()->required(),
                'status' => $schema->string()->enum(['unknown', 'conflicting'])->required(),
                'reason' => $schema->string()->max(300)->required(),
            ])->withoutAdditionalProperties())->required(),
            'summary' => $schema->string()->max(500)->required(),
        ];
    }
}
