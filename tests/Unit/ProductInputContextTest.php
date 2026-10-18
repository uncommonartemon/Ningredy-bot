<?php

namespace Tests\Unit;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductInputContext;
use App\Services\Products\ProductRequestContext;
use Tests\TestCase;

class ProductInputContextTest extends TestCase
{
    public function test_photo_facts_are_not_user_requirements_and_search_codes_are_alternatives(): void
    {
        $update = new TelegramUpdate(['text' => 'Acer AG15-42P-R732 LED 32 GB', 'payload' => ['message' => ['photo' => [['file_id' => 'test']]]]]);
        $update->id = 21;
        $data = ['text' => $update->text, 'has_text' => true, 'identifiers' => [
            $this->identifier('serial', 'UNIQUE-DEVICE-123'),
            $this->identifier('brand', 'Acer'),
            $this->identifier('family', 'Aspire Go 15'),
            $this->identifier('mpn', 'NX.J7WET.017'),
            $this->identifier('model', 'AG15-42P-R732'),
            $this->identifier('ean', '4711474768391'),
        ], 'observations' => [
            ['key' => 'display_backlight', 'name' => 'Backlight', 'value' => 'LED', 'raw_value' => 'LED',
                'source_excerpt' => '15.6 FHD Acer ComfyView LED LCD', 'source_kind' => 'photo_label', 'confidence' => 0.99],
            ['key' => 'ram_capacity', 'name' => 'RAM', 'value' => '32 GB', 'raw_value' => '32 GB DDR4 Memory',
                'source_excerpt' => '32 GB DDR4 Memory', 'source_kind' => 'photo_label', 'confidence' => 0.99],
            ['key' => 'ram_capacity', 'name' => 'RAM', 'value' => '64 GB', 'raw_value' => '64 GB RAM',
                'source_excerpt' => '64 GB RAM', 'source_kind' => 'handwritten', 'confidence' => 0.95],
        ]];
        $update->payload = [...$update->payload, ProductInputContext::PAYLOAD_KEY => ProductInputContext::fromPhotoExtraction($update, $data)];
        $context = ProductInputContext::forUpdate($update);
        $this->assertNull($context['original_operator_request']);
        $this->assertSame(['4711474768391', 'NX.J7WET.017', 'Acer AG15-42P-R732', 'Acer Aspire Go 15'], array_column($context['search_plan'], 'query'));
        $ram = array_values(array_filter($context['input_evidence'], fn ($fact) => $fact['key'] === 'ram_capacity'));
        $this->assertSame(['32 GB', '64 GB'], array_column($ram, 'value'));
        $this->assertSame(['photo_label', 'handwritten'], array_column($ram, 'source_kind'));
        $this->assertSame('telegram-photo:21:1', $ram[0]['source_ref']);
        $this->assertSame('32 GB DDR4 Memory', $ram[0]['source_excerpt']);
        $draft = new ProductDraft(['title' => 'Acer Aspire Go 15', 'model' => 'AG15-42P-R732', 'specifications' => []]);
        $draft->setRelation('telegramUpdate', $update);
        $agent = ProductRequestContext::forDraft($draft)->withOperatorHint('Проверь галерею')->forAgent();
        $this->assertNull($agent['original_operator_request']);
        $this->assertSame($context['input_evidence'], $agent['input_evidence']);
        $this->assertSame($context['search_plan'], $agent['search_plan']);
        $this->assertSame('AG15-42P-R732', $agent['selected_product']['model']);
    }

    public function test_ambiguous_codes_and_invalid_barcodes_remain_evidence_but_are_not_searched(): void
    {
        $update = new TelegramUpdate;
        $update->id = 22;
        $data = ['text' => 'Unclear label', 'has_text' => true, 'identifiers' => [
            [...$this->identifier('mpn', '90NR0I02-M00460'), 'uncertain_characters' => 'I or 1'],
            [...$this->identifier('model', 'GA403UV-NEBULA021W'), 'confidence' => 0.4],
            $this->identifier('ean', '4711474768392'),
            $this->identifier('regulatory', 'N22C6'),
        ], 'observations' => []];
        $update->payload = [ProductInputContext::PAYLOAD_KEY => ProductInputContext::fromPhotoExtraction($update, $data)];
        $context = ProductInputContext::forUpdate($update);
        $this->assertCount(4, $context['input_evidence']);
        $this->assertSame([], $context['search_plan']);
        $this->assertSame([false, false, false, false], array_column($context['search_keys'], 'usable_for_search'));
    }

    public function test_legacy_photo_transcription_never_becomes_a_human_instruction(): void
    {
        $update = new TelegramUpdate(['text' => 'Acer LED LCD 32 GB', 'payload' => ['message' => ['photo' => [['file_id' => 'test']]]]]);
        $update->id = 23;
        $context = ProductInputContext::forUpdate($update);
        $this->assertNull($context['original_operator_request']);
        $this->assertSame('photo_transcription', $context['input_evidence'][0]['source_kind']);
        $this->assertNull($context['input_evidence'][0]['confidence']);
        $this->assertSame('Acer LED LCD 32 GB', $context['input_evidence'][0]['value']);
    }

    public function test_actual_human_caption_and_normal_text_keep_their_authority(): void
    {
        $update = new TelegramUpdate(['text' => 'LED from OCR', 'payload' => ['message' => [
            'photo' => [['file_id' => 'test']], 'caption' => 'Нужна именно чешская клавиатура',
        ]]]);
        $this->assertSame('Нужна именно чешская клавиатура', ProductInputContext::forUpdate($update)['original_operator_request']);
        $update->payload = [ProductInputContext::PAYLOAD_KEY => ProductInputContext::fromPhotoExtraction($update, ['text' => 'LED', 'has_text' => true])];
        $this->assertSame('Нужна именно чешская клавиатура', ProductInputContext::forUpdate($update)['original_operator_request']);
        $text = new TelegramUpdate(['text' => 'Lenovo с 32 GB RAM', 'payload' => []]);
        $this->assertSame('Lenovo с 32 GB RAM', ProductInputContext::forUpdate($text)['original_operator_request']);
    }

    public function test_existing_manual_intake_provenance_is_reused_without_inventing_confidence(): void
    {
        $update = new TelegramUpdate(['payload' => ['_manual_product_intake' => [
            'original_description' => 'Проверь этот ноутбук',
            'fact_provenance' => [
                ['key' => 'display_backlight', 'name' => 'Backlight', 'value' => 'LED', 'source' => 'photo_label', 'quote' => 'LED LCD', 'photo_index' => 2],
                ['key' => 'condition', 'name' => 'Condition', 'value' => 'Used', 'source' => 'description', 'quote' => 'Used', 'photo_index' => null],
            ],
        ]]]);
        $update->id = 24;
        $context = ProductInputContext::forUpdate($update);
        $this->assertSame('Проверь этот ноутбук', $context['original_operator_request']);
        $this->assertSame('telegram-photo:24:2', $context['input_evidence'][0]['source_ref']);
        $this->assertSame('LED LCD', $context['input_evidence'][0]['source_excerpt']);
        $this->assertNull($context['input_evidence'][0]['confidence']);
        $this->assertSame('user_text', $context['input_evidence'][1]['source_kind']);
        $this->assertNull($context['input_evidence'][1]['photo_ref']);
    }

    public function test_visual_description_without_text_does_not_create_known_identifiers(): void
    {
        $update = new TelegramUpdate;
        $update->id = 25;
        $update->payload = [ProductInputContext::PAYLOAD_KEY => ProductInputContext::fromPhotoExtraction($update, [
            'text' => 'Серебристый ноутбук', 'has_text' => false,
            'identifiers' => [$this->identifier('model', 'Guessed model')],
        ])];
        $context = ProductInputContext::forUpdate($update);
        $this->assertSame([], $context['search_keys']);
        $this->assertSame([], $context['search_plan']);
        $this->assertSame('visual_observation', $context['input_evidence'][0]['source_kind']);
        $this->assertNull($context['original_operator_request']);
    }

    private function identifier(string $kind, string $value): array
    {
        return ['kind' => $kind, 'value' => $value, 'raw_value' => $value, 'source_excerpt' => $value,
            'source_kind' => 'photo_label', 'confidence' => 0.99, 'uncertain_characters' => null];
    }
}
