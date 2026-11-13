<?php

namespace Tests\Feature;

use App\Ai\Agents\AttributeDictionaryReviewAgent;
use App\Ai\Tools\CompareCharacteristics;
use App\Ai\Tools\ReadCharacteristic;
use App\Jobs\AuditCharacteristics;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CharacteristicQuestion;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CharacteristicAudit;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Decision of 2026-11-13: the characteristic audit. The model reads every characteristic with its
 * values and products, applies what it is sure of and puts what it doubts to the operator with
 * two buttons; an answer is kept, a pair kept apart is not asked again.
 */
class CharacteristicAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]])]);
        AttributeDefinition::query()->create(['key' => 'fans', 'label' => 'Fans']);
        AttributeDefinition::query()->create(['key' => 'cooling_fan_count', 'label' => 'Cooling fan count']);
        AttributeDefinition::query()->create(['key' => 'capacity', 'label' => 'Capacity']);
        AttributeDefinition::query()->create(['key' => 'cooling_noise', 'label' => 'Noise']);
        AttributeDefinition::query()->create(['key' => 'warranty', 'label' => 'Warranty']);
    }

    public function test_the_audit_applies_what_is_sure_and_asks_about_the_rest(): void
    {
        $this->model([
            ['from' => 'fans', 'into' => 'cooling_fan_count', 'certainty' => 'sure', 'reason' => 'Синоним.'],
            ['from' => 'capacity', 'into' => 'storage', 'certainty' => 'doubtful', 'reason' => 'Ёмкость может быть и у памяти.'],
        ], [
            ['key' => 'cooling_noise', 'group' => ['id' => null, 'name' => 'Cooling', 'name_cs' => 'Chlazení'], 'certainty' => 'sure', 'reason' => 'Охлаждение.'],
            ['key' => 'warranty', 'group' => ['id' => null, 'name' => 'General', 'name_cs' => null], 'certainty' => 'doubtful', 'reason' => 'Общая группа?'],
        ]);

        (new AuditCharacteristics('98765'))->handle(app(CharacteristicAudit::class), app(TelegramClient::class));

        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'fans']);
        $this->assertSame('Cooling', AttributeDefinition::query()->where('key', 'cooling_noise')->sole()->group?->name);
        $this->assertDatabaseHas('attribute_definitions', ['key' => 'capacity']);
        $this->assertNull(AttributeDefinition::query()->where('key', 'warranty')->sole()->attribute_group_id);
        $questions = CharacteristicQuestion::query()->open()->orderBy('id')->get();
        $this->assertSame(['merge', 'group'], $questions->pluck('kind')->all());
        $this->assertSame(777, $questions[0]->message_id);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), 'Объединено сразу: 1.')
            && str_contains((string) $request['text'], 'Сомнительных: 2'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), 'Ёмкость может быть и у памяти.')
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'chq:y:'.$questions[0]->id);
    }

    public function test_a_merge_the_model_is_sure_of_is_asked_when_a_product_carries_both_at_different_values(): void
    {
        $product = Product::query()->create(['title' => 'Cooler X', 'category_id' => Category::query()->where('slug', 'components')->value('id'),
            'product_type' => 'component', 'status' => 'published', 'is_active' => true]);
        foreach (['fans' => '2', 'cooling_fan_count' => '3'] as $key => $value) {
            AttributeValue::query()->create(['attribute_definition_id' => AttributeDefinition::query()->where('key', $key)->value('id'),
                'product_id' => $product->id, 'value' => $value]);
        }
        $this->model([['from' => 'fans', 'into' => 'cooling_fan_count', 'certainty' => 'sure', 'reason' => 'Синоним.']], []);

        $result = app(CharacteristicAudit::class)->run('98765');

        $this->assertSame([], $result['merged']);
        $this->assertDatabaseHas('attribute_definitions', ['key' => 'fans']);
        $this->assertStringContainsString('значения разные', $result['questions'][0]->payload['reason']);
    }

    public function test_the_operator_answers_with_the_buttons_and_a_pair_kept_apart_is_not_asked_again(): void
    {
        $this->model([
            ['from' => 'fans', 'into' => 'cooling_fan_count', 'certainty' => 'doubtful', 'reason' => 'Похоже.'],
            ['from' => 'capacity', 'into' => 'storage', 'certainty' => 'doubtful', 'reason' => 'Ёмкость.'],
        ], []);
        [$merge, $apart] = app(CharacteristicAudit::class)->run('98765')['questions'];

        $this->press('chq:y:'.$merge->id, 5001);
        $this->press('chq:n:'.$apart->id, 5002);
        $this->press('chq:y:'.$apart->id, 5003);

        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'fans']);
        $this->assertSame('applied', $merge->fresh()->status);
        $this->assertSame('declined', $apart->fresh()->status);
        $this->assertDatabaseHas('attribute_definitions', ['key' => 'capacity']);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), '✅ Объединено: fans → cooling_fan_count'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), 'Уже решено.'));

        $again = app(CharacteristicAudit::class)->run('98765');
        $this->assertSame([], $again['questions']);
        // The model is told what the operator declined.
        $this->assertSame(0, CharacteristicQuestion::query()->open()->count());
    }

    public function test_the_command_and_the_keyboard_button_start_the_audit(): void
    {
        Queue::fake();

        $this->say('/audit', 6001);
        $this->say('🧹 Аудит характеристик', 6002);

        Queue::assertPushed(AuditCharacteristics::class, 2);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), 'Аудит характеристик начат'));
    }

    public function test_the_auditor_reads_a_characteristic_and_compares_two(): void
    {
        $product = Product::query()->create(['title' => 'Cooler Y', 'category_id' => Category::query()->where('slug', 'components')->value('id'),
            'product_type' => 'component', 'status' => 'published', 'is_active' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'fingerprint' => 'y', 'name' => 'Default', 'is_default' => true, 'is_active' => true]);
        AttributeValue::query()->create(['attribute_definition_id' => AttributeDefinition::query()->where('key', 'fans')->value('id'),
            'product_variant_id' => $variant->id, 'value' => '2']);
        AttributeValue::query()->create(['attribute_definition_id' => AttributeDefinition::query()->where('key', 'cooling_noise')->value('id'),
            'product_id' => $product->id, 'value' => '22 dB']);

        $detail = json_decode((string) (new ReadCharacteristic)->handle(new Request(['key' => 'fans'])), true);
        $compare = json_decode((string) (new CompareCharacteristics)->handle(new Request(['keys' => ['fans', 'cooling_noise']])), true);

        $this->assertSame(['2 ×1'], $detail['values']);
        $this->assertSame(1, $detail['products_count']);
        $this->assertSame(['cooling_noise' => 1], $detail['on_the_same_products_as']);
        $this->assertSame(1, $compare['products_with_several']);
        $this->assertSame(['product' => 'Cooler Y', 'fans' => '2', 'cooling_noise' => '22 dB'], $compare['side_by_side'][0]);
    }

    private function model(array $merges, array $groups): void
    {
        AttributeDictionaryReviewAgent::fake(fn (): array => ['merges' => $merges, 'groups' => $groups])->preventStrayPrompts();
    }

    private function press(string $data, int $updateId): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => $updateId, 'callback_query' => [
            'id' => 'callback-'.$updateId, 'from' => ['id' => 12345, 'username' => 'admin'], 'data' => $data,
            'message' => ['message_id' => $updateId, 'chat' => ['id' => 98765]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function say(string $text, int $updateId): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => $updateId, 'message' => [
            'message_id' => $updateId, 'from' => ['id' => 12345, 'username' => 'admin'], 'chat' => ['id' => 98765], 'text' => $text,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }
}
