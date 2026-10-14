<?php

namespace Tests\Feature;

use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductDraftWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A product's own text is English; its only translation is Czech, and it
 * covers the specifications as well as the title and description. Any other
 * storefront language shows the English text.
 */
class CatalogTranslationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishing_puts_the_czech_translation_on_each_specification_and_its_name(): void
    {
        // A label edited by hand earlier is not overwritten by a card.
        AttributeDefinition::query()->create(['key' => 'weight', 'label' => 'Weight', 'data_type' => 'text',
            'translations' => ['en' => 'Weight (net)']]);
        $draft = $this->publishableDraft(
            specifications: [
                ['key' => 'wireless', 'name' => 'Wireless', 'value' => 'Intel Wi-Fi 6E AX211 (2x2)'],
                ['key' => 'usb_a', 'name' => 'USB-A ports', 'value' => '3 x USB 3.2 Gen 2 Type-A'],
                ['key' => 'weight', 'name' => 'Weight', 'value' => '2.4 kg'],
            ],
            translations: ['cs' => [
                'title' => 'Herní notebook MSI Katana 15 HX',
                'description' => 'Ověřené parametry.',
                'specifications' => [
                    ['key' => 'wireless', 'name' => 'Bezdrátové připojení', 'value' => 'Intel Wi-Fi 6E AX211 (2x2)'],
                    ['key' => 'weight', 'name' => 'Hmotnost', 'value' => '2,4 kg'],
                ],
            ]],
        );

        $product = app(ProductDraftWorkflow::class)->approve($draft);

        // Title and description only: specifications live on their values.
        $this->assertSame(['cs' => ['title' => 'Herní notebook MSI Katana 15 HX', 'description' => 'Ověřené parametry.']],
            $product->translations);
        $this->assertSame(['cs' => '2,4 kg'], $this->valueOf('weight')->translations);
        $this->assertSame(['cs' => 'Intel Wi-Fi 6E AX211 (2x2)'], $this->valueOf('wireless')->translations);
        // No translation for this one: the storefront shows the English value.
        $this->assertNull($this->valueOf('usb_a')->translations);

        $this->assertSame(['en' => 'Wireless', 'cs' => 'Bezdrátové připojení'], $this->definition('wireless')->translations);
        $this->assertSame(['en' => 'USB-A ports'], $this->definition('usb_a')->translations);
        $this->assertSame(['en' => 'Weight (net)', 'cs' => 'Hmotnost'], $this->definition('weight')->translations);
    }

    public function test_an_english_value_edited_by_hand_drops_its_stale_translation(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve($this->publishableDraft(
            specifications: [['key' => 'weight', 'name' => 'Weight', 'value' => '2.4 kg']],
            translations: ['cs' => ['title' => 'Notebook', 'description' => 'Popis.', 'specifications' => [
                ['key' => 'weight', 'name' => 'Hmotnost', 'value' => '2,4 kg'],
            ]]],
        ));
        $weight = $this->valueOf('weight');

        $weight->update(['value' => '2.5 kg']);
        $this->assertNull($weight->fresh()->translations, 'a translation of the old value must not stay');

        $weight->update(['value' => '2.6 kg', 'translations' => ['cs' => '2,6 kg']]);
        $this->assertSame(['cs' => '2,6 kg'], $weight->fresh()->translations);
        $this->assertNotNull($product->fresh());
    }

    public function test_the_product_page_receives_the_translations_it_chooses_from(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve($this->publishableDraft(
            specifications: [['key' => 'wireless', 'name' => 'Wireless', 'value' => 'Intel Wi-Fi 6E AX211']],
            translations: ['cs' => ['title' => 'Notebook', 'description' => 'Popis.', 'specifications' => [
                ['key' => 'wireless', 'name' => 'Bezdrátové připojení', 'value' => 'Intel Wi-Fi 6E AX211'],
            ]]],
        ));

        $this->get(route('products.show', $product))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.translations.cs.title', 'Notebook')
            ->where('product.attributes.0.key', 'wireless')
            ->where('product.attributes.0.value', 'Intel Wi-Fi 6E AX211')
            ->where('product.attributes.0.translations.cs', 'Intel Wi-Fi 6E AX211')
            ->where('product.attributes.0.label_translations.en', 'Wireless')
            ->where('product.attributes.0.label_translations.cs', 'Bezdrátové připojení')
            ->etc());
    }

    private function valueOf(string $key): AttributeValue
    {
        return AttributeValue::query()->whereHas('definition', fn ($query) => $query->where('key', $key))->sole();
    }

    private function definition(string $key): AttributeDefinition
    {
        return AttributeDefinition::query()->where('key', $key)->sole();
    }

    /**
     * @param  array<int, array<string, string>>  $specifications
     * @param  array<string, mixed>  $translations
     */
    private function publishableDraft(array $specifications, array $translations): ProductDraft
    {
        $update = TelegramUpdate::query()->create(['update_id' => random_int(2000, 90000), 'telegram_user_id' => '12345',
            'chat_id' => '98765', 'message_id' => 55, 'text' => 'MSI Katana 15 HX', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'gpt-5.4',
            'status' => 'completed', 'prompt' => 'MSI Katana 15 HX', 'started_at' => now()]);
        Queue::fake([StoreProductImages::class]);

        $draft = ProductDraft::query()->create([
            'primary_source_url' => 'https://example.com/verified-product',
            'specifications_reconciled_source_url' => 'https://example.com/verified-product',
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '12345',
            'title' => 'MSI Katana 15 HX Gaming Laptop', 'brand' => 'MSI', 'model' => 'Katana 15 HX B14WGK-293US',
            'product_type' => 'laptop', 'category' => 'laptops', 'color' => 'Black',
            'description' => 'Verified specifications.', 'specifications' => $specifications, 'translations' => $translations,
            'sources' => [], 'image_urls' => [], 'confidence' => 0.9, 'status' => 'pending_review',
        ]);
        $draft->media()->create([
            'disk' => 'public', 'path' => 'drafts/'.$draft->id.'/photo.webp', 'source_url' => 'https://example.com/photo.webp',
            'role' => 'primary', 'mime_type' => 'image/webp', 'width' => 1200, 'height' => 1200, 'file_size' => 2048,
            'checksum' => hash('sha256', 'photo-'.$draft->id), 'verification_status' => 'verified', 'sort_order' => 0,
            'is_primary' => true,
        ]);

        return $draft;
    }
}
