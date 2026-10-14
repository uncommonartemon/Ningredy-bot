<?php

namespace App\Services\Products;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Shared observations, not a server-side ranking or a verdict of truth. */
final class ProductTextEvidenceContract
{
    public const SCOPES = ['exact_configuration', 'shared_chassis', 'product_family', 'unknown'];

    public static function observationSchema(JsonSchema $schema, bool $includeGroup = false): mixed
    {
        return $schema->object([
            'key' => $schema->string()->description('The same stable snake_case field key as the selected specification; several sources may report different values for this key.')->max(100)->required(),
            'name' => $schema->string()->max(255)->required(),
            'value' => $schema->string()->max(2000)->required(),
            ...($includeGroup ? ['group' => AiAttributeGroups::schema($schema)] : []),
            'source_url' => $schema->string()->max(2048)->required(),
            'source_excerpt' => $schema->string()->description('Verbatim observed text, including qualifications such as optional or up to.')->max(800)->required(),
            'source_type' => $schema->string()->description('Publisher role supported by the document, not guessed from its domain or a PDF extension; null when unknown.')->enum(ProductSourceTypes::VALUES)->nullable()->required(),
            'scope' => $schema->string()->description('Scope of this observation, not of the website: exact selected configuration, demonstrably shared chassis property, family/options, or unknown.')->enum(self::SCOPES)->required(),
            'applicability' => $schema->string()->description('Why this observation applies or does not apply to the selected configuration/revision/region. Prefer exact applicable official specifications when comparably specific evidence is available; generic official family data never overrides an exact offer merely by authority. Conflicts remain observations, not majority votes.')->max(800)->required(),
        ])->withoutAdditionalProperties();
    }

    /** Presentation metadata must never invalidate otherwise usable evidence. */
    public static function normalizeGroups(mixed $observations): mixed
    {
        if (! is_array($observations)) {
            return $observations;
        }

        foreach ($observations as &$observation) {
            if (is_array($observation) && array_key_exists('group', $observation)) {
                $observation['group'] = AiAttributeGroups::normalize($observation['group']);
            }
        }

        return $observations;
    }

    public static function rules(string $field, bool $optional = false, bool $includeGroup = false): array
    {
        return [
            $field => [$optional ? 'sometimes' : 'present', 'array'],
            "$field.*.key" => ['required', 'string', 'regex:/^[a-z0-9_]+$/', 'max:100'],
            "$field.*.name" => ['required', 'string', 'max:255'],
            "$field.*.value" => ['required', 'string', 'max:2000'],
            ...($includeGroup ? [
                "$field.*.group" => ['sometimes', 'nullable', 'array:id,name,name_cs'],
                "$field.*.group.id" => ['sometimes', 'nullable', 'integer', 'min:1'],
                "$field.*.group.name" => ['sometimes', 'nullable', 'string', 'max:255'],
                "$field.*.group.name_cs" => ['sometimes', 'nullable', 'string', 'max:255'],
            ] : []),
            "$field.*.source_url" => ['required', 'url:http,https', 'max:2048'],
            "$field.*.source_excerpt" => ['required', 'string', 'max:800'],
            "$field.*.applicability" => ['required', 'string', 'max:800'],
            // Missing metadata on old observations stays unknown. A publisher
            // label never grants trust or replaces the applicability judgment.
            "$field.*.source_type" => ['sometimes', 'nullable', 'in:'.implode(',', ProductSourceTypes::VALUES)],
            "$field.*.scope" => ['sometimes', 'in:'.implode(',', self::SCOPES)],
        ];
    }
}
