<?php

namespace Tests\Unit;

use App\Ai\Agents\ProductSpecificationRepairAgent;
use App\Models\ProductDraft;
use App\Services\ProductSearch\Recovery\CardRepairPatch;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\TestCase;

class CardRepairPatchTest extends TestCase
{
    public function test_schema_index_becomes_a_stable_key_and_only_the_bad_url_changes(): void
    {
        $base = $this->candidate();
        $repair = $this->prepare($base, ['specifications.1.source_url' => ['Invalid URL']]);
        $this->assertSame(['specifications.ram.source_url'], array_keys($repair['paths']));
        $result = app(CardRepairPatch::class)->apply($repair, $this->repairResponse($repair, ['specifications.ram.source_url' => 'https://maker.example/ram']));
        $expected = $base;
        $expected['specifications'][1]['source_url'] = 'https://maker.example/ram';
        $this->assertSame($expected, $result);
        $this->assertSame([], app(CardRepairPatch::class)->forAgent($repair)['conditional_paths']);
        $this->assertSame($base['specifications'][1], app(CardRepairPatch::class)->forAgent($repair)['affected_candidate_facts']['ram']);
    }

    public function test_long_commentary_is_shortened_instead_of_discarding_the_patch(): void
    {
        $base = $this->candidate();
        $repair = $this->prepare($base, ['specifications.1.source_url' => ['Invalid URL']]);
        $patch = $this->repairResponse($repair, ['specifications.ram.source_url' => 'https://maker.example/ram']);
        $patch['summary'] = str_repeat('Пояснение ', 80);

        $result = app(CardRepairPatch::class)->apply($repair, $patch);
        $this->assertSame('https://maker.example/ram', $result['specifications'][1]['source_url']);

        $notReady = ['base_hash' => $repair['base_hash'], 'decision' => 'not_ready', 'changes' => [],
            'unresolved_fields' => [['key' => 'ram', 'status' => 'unknown', 'reason' => str_repeat('Нет данных ', 50)]], 'summary' => 'Blocked'];
        $blocked = app(CardRepairPatch::class)->apply($repair, $notReady);
        $this->assertSame('not_ready', $blocked['overall_status']);
        $this->assertSame(300, mb_strlen($blocked['unresolved_fields'][0]['reason']));
    }

    public function test_empty_current_citation_list_does_not_forbid_adding_a_required_source(): void
    {
        $base = $this->candidate();
        $base['specifications'][1]['considered_source_urls'] = [];
        $repair = $this->prepare($base, ['ram: differing observations require reason and considered_source_urls.']);
        $payload = app(CardRepairPatch::class)->forAgent($repair);
        $this->assertSame([], $payload['current_values']['specifications.ram.considered_source_urls']);
        $this->assertContains('specifications.ram.considered_source_urls', $payload['allowed_paths']);
        $this->assertArrayNotHasKey('allowed_values', $payload);
        $result = app(CardRepairPatch::class)->apply($repair, $this->repairResponse($repair, [
            'specifications.ram.considered_source_urls' => ['https://manufacturer.example/other-offer'],
            'specifications.ram.reason' => 'The other offer has different installed memory.',
        ]));
        $this->assertSame(['https://manufacturer.example/other-offer'], $result['specifications'][1]['considered_source_urls']);
        $this->assertSame($base['specifications'][1]['value'], $result['specifications'][1]['value']);
        $this->assertSame($base['specifications'][0], $result['specifications'][0]);
        $this->assertSame($base['description'], $result['description']);
    }

    public function test_recovery_can_expand_only_fields_of_already_affected_facts(): void
    {
        $repair = $this->prepare($this->candidate(), ['ram: considered_source_urls missing.']);
        $helper = app(CardRepairPatch::class);
        $expanded = $helper->expandForRecovery($repair, ['specifications.ram.source_excerpt']);
        $this->assertNotSame($repair['base_hash'], $expanded['base_hash']);
        $this->assertSame($repair['base'], $expanded['base']);
        $this->assertRejected($expanded, $this->repairResponse($repair, ['specifications.ram.reason' => 'Old scope']));
        $this->expectException(ValidationException::class);
        $helper->expandForRecovery($repair, ['specifications.cpu.value']);
    }

    public function test_unrelated_field_new_specification_and_wrong_hash_cannot_escape_scope(): void
    {
        $repair = $this->prepare($this->candidate(), ['specifications.1.source_url' => ['Invalid URL']]);
        foreach (['specifications.cpu.value', 'specifications.new_fact.value', 'description'] as $path) {
            $this->assertRejected($repair, $this->repairResponse($repair, [$path => 'Changed']));
        }
        $this->assertRejected($repair, [...$this->repairResponse($repair, ['specifications.ram.source_url' => 'https://maker.example/ram']), 'base_hash' => str_repeat('0', 64)]);
    }

    public function test_metadata_repair_cannot_change_values_or_prose_and_preserves_reviewed_sources(): void
    {
        $base = $this->candidate();
        $repair = $this->prepare($base, ['ram: differing or dropped cited observations require reason and considered_source_urls.']);
        $this->assertRejected($repair, $this->repairResponse($repair, ['specifications.ram.value' => '32 GB']));
        $this->assertRejected($repair, $this->repairResponse($repair, ['description' => ['find' => '16 GB', 'replace' => '32 GB']]));
        $result = app(CardRepairPatch::class)->apply($repair, $this->repairResponse($repair, ['specifications.ram.considered_source_urls' => ['https://old.example/ram']]));
        $this->assertSame($base['specifications'][0], $result['specifications'][0]);
        $this->assertSame($base['description'], $result['description']);
    }

    public function test_actual_value_change_can_update_only_its_translation_and_one_dependent_prose_fragment(): void
    {
        $base = $this->candidate();
        $repair = $this->prepare($base, ['ram: source excerpt was not observed.']);
        $changes = ['specifications.ram.value' => '32 GB', 'specifications.ram.evidence' => 'corrected',
            'translations.cs.specifications.ram' => ['key' => 'ram', 'name' => 'Paměť', 'value' => '32 GB'],
            'description' => ['find' => '16 GB', 'replace' => '32 GB'],
            'translations.cs.description' => ['find' => '16 GB', 'replace' => '32 GB']];
        $result = app(CardRepairPatch::class)->apply($repair, $this->repairResponse($repair, $changes));
        $this->assertSame('Computer with 32 GB RAM. Original CPU claim.', $result['description']);
        $this->assertSame('Počítač s 32 GB RAM.', $result['translations']['cs']['description']);
        $this->assertSame($base['specifications'][0], $result['specifications'][0]);
        $this->assertSame($base['translations']['cs']['specifications'][0], $result['translations']['cs']['specifications'][0]);
        $this->assertRejected($repair, $this->repairResponse($repair, ['description' => $changes['description']]));
        $this->assertRejected($repair, $this->repairResponse($repair, ['specifications.ram.value' => '32 GB',
            'translations.cs.specifications.ram' => ['key' => 'cpu', 'name' => 'CPU', 'value' => 'Other CPU']]));
        $this->assertRejected($repair, $this->repairResponse($repair, ['specifications.ram.value' => '32 GB',
            'description' => ['find' => $base['description'], 'replace' => 'An unrelated new processor claim.']]));
        $this->assertRejected($repair, $this->repairResponse($repair, ['specifications.ram.unit' => 'TB',
            'description' => ['find' => '16 GB', 'replace' => '32 TB']]));
    }

    public function test_dropping_a_fact_without_a_prose_claim_is_allowed_but_cannot_delete_other_claims(): void
    {
        $base = $this->candidate();
        $base['specifications'][] = ['key' => 'optional', 'name' => 'Optional', 'value' => 'Optional value', 'evidence' => 'confirmed'];
        $repair = $this->prepare($base, ['optional: source excerpt was not observed.']);
        $result = app(CardRepairPatch::class)->apply($repair, $this->repairResponse($repair, ['specifications.optional.evidence' => 'dropped']));
        $this->assertSame($base['description'], $result['description']);
        $repair = $this->prepare($base, ['ram: source excerpt was not observed.']);
        $result = app(CardRepairPatch::class)->apply($repair, $this->repairResponse($repair, ['specifications.ram.evidence' => 'dropped',
            'description' => ['find' => 'Computer with 16 GB RAM.', 'replace' => '']]));
        $this->assertSame(' Original CPU claim.', $result['description']);
        $this->assertRejected($repair, $this->repairResponse($repair, ['specifications.ram.evidence' => 'dropped',
            'description' => ['find' => $base['description'], 'replace' => '']]));
    }

    public function test_numeric_count_cannot_rewrite_an_unrelated_number_or_ambiguous_claim(): void
    {
        $base = $this->candidate();
        $base['specifications'][] = ['key' => 'slots', 'name' => 'Slots', 'value' => '2', 'evidence' => 'confirmed'];
        $repair = $this->prepare($base, ['slots: source excerpt was not observed.']);
        foreach (['32 GB RAM', '2 slots available'] as $description) {
            $base['description'] = $description;
            $repair = $this->prepare($base, ['slots: source excerpt was not observed.']);
            $this->assertRejected($repair, $this->repairResponse($repair, ['specifications.slots.value' => '3',
                'description' => ['find' => '2', 'replace' => '3']]));
        }
    }

    public function test_brand_and_status_changes_invalidate_the_patch_and_agent_retains_tool_limits(): void
    {
        $base = $this->candidate();
        $repair = $this->prepare($base, ['specifications.1.source_url' => ['Invalid URL']]);
        foreach (['brand' => 'Other brand', 'status' => 'rejected'] as $field => $value) {
            $draft = $this->draft($base);
            $draft->{$field} = $value;
            try {
                app(CardRepairPatch::class)->assertCurrent($draft, ['url' => 'https://shop.example/product'], 10, $repair);
                $this->fail('Changed baseline was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('repair_patch', $exception->errors());
            }
        }
        $options = TextGenerationOptions::forAgent(new ProductSpecificationRepairAgent);
        $this->assertSame(3, $options->maxSteps);
        $this->assertSame(16000, $options->maxTokens);
    }

    public function test_failed_patch_retains_base_and_scope_but_a_new_generation_invalidates_it(): void
    {
        $base = $this->candidate();
        $draft = $this->draft($base);
        $repair = $this->prepare($base, ['specifications.1.source_url' => ['Invalid URL']]);
        $feedback = ['reason' => 'invalid_response', 'repair_context' => ['previous_candidate' => $base,
            'patch_repair' => $repair, 'validation_errors' => ['repair_patch' => ['Wrong hash']]]];
        $this->assertSame($repair, app(CardRepairPatch::class)->prepare($draft, ['url' => 'https://shop.example/product'], 10, $feedback));
        $draft->telegram_update_id = 11;
        $this->assertNull(app(CardRepairPatch::class)->prepare($draft, ['url' => 'https://shop.example/product'], 10, $feedback));
        $this->expectException(ValidationException::class);
        app(CardRepairPatch::class)->assertCurrent($draft, ['url' => 'https://shop.example/product'], 10, $repair);
    }

    public function test_structured_offer_targets_and_closed_not_ready_do_not_expand_scope(): void
    {
        $base = $this->candidate();
        $feedback = ['reason' => 'invalid_response', 'repair_context' => ['previous_candidate' => $base,
            'repair_targets' => ['specification_keys' => ['ram'], 'requires_photo_reassessment' => true],
            'validation_errors' => ['offer_selection' => ['Photo target changed']]]];
        $repair = app(CardRepairPatch::class)->prepare($this->draft($base), ['url' => 'https://shop.example/product'], 10, $feedback);
        $patch = ['base_hash' => $repair['base_hash'], 'decision' => 'not_ready', 'changes' => [],
            'unresolved_fields' => [['key' => 'ram', 'status' => 'unknown', 'reason' => 'Need photo reassessment']], 'summary' => 'Photo check required'];
        $result = app(CardRepairPatch::class)->apply($repair, $patch);
        $this->assertSame('not_ready', $result['overall_status']);
        $this->assertSame($base['specifications'], $result['specifications']);
        $patch['unresolved_fields'][0]['key'] = 'cpu';
        $this->assertRejected($repair, $patch);
        $this->assertNull(app(CardRepairPatch::class)->prepare($this->draft($base), [], 10, ['repair_context' => ['previous_candidate' => []]]));
    }

    private function assertRejected(array $repair, array $patch): void
    {
        try {
            app(CardRepairPatch::class)->apply($repair, $patch);
            $this->fail('Out-of-scope patch was accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function prepare(array $base, array $errors): array
    {
        return app(CardRepairPatch::class)->prepare($this->draft($base), ['url' => 'https://shop.example/product'], 10,
            ['reason' => 'invalid_response', 'repair_context' => ['previous_candidate' => $base, 'validation_errors' => $errors]]);
    }

    private function draft(array $base): ProductDraft
    {
        return new ProductDraft(['id' => 1, 'telegram_update_id' => 10, 'title' => $base['title'], 'model' => $base['model'],
            'description' => $base['description'], 'specifications' => $base['specifications']]);
    }

    private function repairResponse(array $repair, array $changes): array
    {
        return ['base_hash' => $repair['base_hash'], 'decision' => 'patch',
            'changes' => collect($changes)->map(fn ($value, $path): array => ['path' => $path, 'value_json' => json_encode($value)])->values()->all(),
            'unresolved_fields' => [], 'summary' => 'Repaired'];
    }

    private function candidate(): array
    {
        return ['title' => 'Computer', 'model' => 'M1', 'color' => 'Blue', 'overall_status' => 'reconciled',
            'description' => 'Computer with 16 GB RAM. Original CPU claim.',
            'specifications' => [
                ['key' => 'cpu', 'name' => 'CPU', 'value' => 'Original CPU', 'evidence' => 'confirmed', 'considered_source_urls' => ['https://old.example/cpu']],
                ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'confirmed', 'source_url' => 'maker.example/ram'],
            ], 'translations' => ['cs' => ['description' => 'Počítač s 16 GB RAM.', 'specifications' => [
                ['key' => 'cpu', 'name' => 'CPU', 'value' => 'Původní CPU'], ['key' => 'ram', 'name' => 'Paměť', 'value' => '16 GB'],
            ]]]];
    }
}
