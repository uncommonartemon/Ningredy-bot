<?php

namespace Tests\Feature;

use App\Ai\Agents\CatalogTranslationAgent;
use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Filament\Pages\CatalogLanguagesPage;
use App\Jobs\TranslateProductText;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Products\CatalogLocales;
use App\Services\Products\CatalogTextTranslator;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Database\Seeders\StarterCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operator, 2026-11-01: the shop speaks Czech and Ukrainian; English is the text the
 * bot reads on the web and checks facts against, kept as the source, not shown.
 */
class CatalogLanguagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_czech_and_ukrainian_are_made_and_shown_unless_the_admin_says_otherwise(): void
    {
        $this->assertSame(['cs' => 'Čeština', 'uk' => 'Українська'], CatalogLocales::contentTranslations());
        $this->assertSame(['cs', 'uk'], CatalogLocales::storefrontLocales());
        // The operator reads Russian; of the languages made, Ukrainian is the closest.
        $this->assertSame('uk', CatalogLocales::telegramCardLocale());

        CatalogLocales::save(['cs', 'ru'], ['uk', 'en'], 'ru');

        $this->assertSame(['cs' => 'Čeština', 'ru' => 'Русский'], CatalogLocales::contentTranslations());
        $this->assertSame(['uk', 'en'], CatalogLocales::storefrontLocales());
        $this->assertSame('ru', CatalogLocales::telegramCardLocale());

        // A Telegram language the bot no longer makes falls back to one it does.
        CatalogLocales::save(['cs'], ['cs'], 'ru');
        $this->assertSame('cs', CatalogLocales::telegramCardLocale());
        // No translation at all: the card stays in its English source.
        CatalogLocales::save([], ['cs'], 'cs');
        $this->assertSame([], CatalogLocales::contentTranslations());
        $this->assertSame('en', CatalogLocales::telegramCardLocale());
    }

    public function test_the_card_assembly_translates_into_every_enabled_language(): void
    {
        $agent = app(ProductSpecificationReconciliationAgent::class);
        $schema = $agent->schema(new JsonSchemaTypeFactory);

        $this->assertSame(['cs', 'uk'], array_keys($schema['translations']->toArray()['properties']));
        $this->assertStringContainsString('translations.cs (Čeština), translations.uk (Українська)', (string) $agent->instructions());

        CatalogLocales::save(['uk'], ['uk'], 'uk');
        $this->assertSame(['uk'], array_keys(app(ProductSpecificationReconciliationAgent::class)->schema(new JsonSchemaTypeFactory)['translations']->toArray()['properties']));
    }

    public function test_the_telegram_card_shows_the_description_in_the_chosen_language(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 71]]));
        $draft = $this->draft(['cs' => ['title' => 'Notebook', 'description' => 'Ověřený popis.'],
            'uk' => ['title' => 'Ноутбук', 'description' => 'Перевірений опис.']]);

        app(DraftTelegramPresenter::class)->sendControls(app(TelegramClient::class), '98765', $draft);
        CatalogLocales::save(['cs', 'uk'], ['cs', 'uk'], 'en');
        app(DraftTelegramPresenter::class)->sendControls(app(TelegramClient::class), '98765', $draft->fresh());

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Перевірений опис.'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Verified English description.'));
    }

    public function test_the_storefront_receives_its_languages(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('catalogLocales.storefront', ['cs', 'uk'])
            ->where('catalogLocales.content', ['cs', 'uk'])
            ->etc());
    }

    public function test_missing_translations_are_filled_and_existing_ones_are_kept(): void
    {
        $product = $this->product();
        $requests = [];
        CatalogTranslationAgent::fake(function (string $prompt) use (&$requests): array {
            $requests[] = json_decode($prompt, true);
            $translations = [];
            foreach ($requests[0]['items'] as $item) {
                foreach ($item['locales'] as $locale) {
                    $translations[] = ['locale' => $locale, 'id' => $item['id'], 'text' => strtoupper($locale).': '.$item['text']];
                }
            }

            return ['translations' => $translations];
        })->preventStrayPrompts();

        $filled = app(CatalogTextTranslator::class)->translate($product);

        $product->refresh();
        // The Czech title written by hand stays; what was missing is filled.
        $this->assertSame('Ruční název', $product->translations['cs']['title']);
        $this->assertSame('CS: Fast laptop for work.', $product->translations['cs']['description']);
        $this->assertSame('UK: Lenovo ThinkPad E14 laptop', $product->translations['uk']['title']);
        $value = $product->attributes()->with('definition')->where('value', 'Intel Core i5')->sole();
        $this->assertSame('UK: Intel Core i5', $value->translations['uk']);
        $this->assertSame('UK: Processor', $value->definition->translations['uk']);
        // Codes and numbers are not sent: "16 GB" reads the same everywhere.
        $ids = array_column($requests[0]['items'], 'id');
        $this->assertNotContains('value.'.$product->attributes()->where('value', '16 GB')->value('id'), $ids);
        $this->assertGreaterThan(0, $filled);
        $this->assertDatabaseHas('ai_runs', ['status' => 'completed']);

        // Nothing is missing any more: no second paid call.
        $this->assertSame([], app(CatalogTextTranslator::class)->pendingProductIds());
        $this->assertSame(0, app(CatalogTextTranslator::class)->translate($product->fresh()));
        $this->assertCount(1, $requests);
    }

    public function test_the_admin_page_saves_the_languages_and_queues_the_missing_translations(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Queue::fake();
        $product = $this->product();

        Livewire::test(CatalogLanguagesPage::class)
            ->set('data.translations', ['cs', 'ru'])->set('data.storefront', ['cs', 'en'])->set('data.telegram_card', 'ru')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(['cs' => 'Čeština', 'ru' => 'Русский'], CatalogLocales::contentTranslations());
        $this->assertSame(['cs', 'en'], CatalogLocales::storefrontLocales());

        Livewire::test(CatalogLanguagesPage::class)->callAction('translateMissing');
        Queue::assertPushed(TranslateProductText::class, fn (TranslateProductText $job): bool => $job->productId === $product->id);
    }

    public function test_the_starter_categories_carry_their_names_in_the_shop_languages(): void
    {
        app(StarterCatalogSeeder::class)->run();

        $laptops = Category::query()->where('slug', 'laptops')->sole();
        $this->assertSame(['cs' => 'Notebooky', 'en' => 'Laptops', 'uk' => 'Ноутбуки'],
            $laptops->translations()->orderBy('locale')->pluck('name', 'locale')->all());
    }

    private function product(): Product
    {
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $product = Product::query()->create(['category_id' => $category->id, 'title' => 'Lenovo ThinkPad E14 laptop',
            'slug' => 'lenovo-thinkpad-e14', 'description' => 'Fast laptop for work.', 'status' => 'published', 'is_active' => true,
            'translations' => ['cs' => ['title' => 'Ruční název']]]);
        $cpu = AttributeDefinition::query()->where('key', 'cpu')->firstOrFail();
        // A definition keeps its English name among its translations; its label may be Russian.
        $cpu->update(['translations' => ['en' => 'Processor']]);
        $ram = AttributeDefinition::query()->where('key', 'ram')->firstOrFail();
        $product->attributes()->create(['attribute_definition_id' => $cpu->id, 'value' => 'Intel Core i5', 'normalized_value' => 'intel core i5']);
        $product->attributes()->create(['attribute_definition_id' => $ram->id, 'value' => '16 GB', 'normalized_value' => '16 gb']);

        return $product;
    }

    private function draft(array $translations): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 910315, 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'text' => 'Lenovo ThinkPad E14', 'payload' => [], 'status' => 'received']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'local', 'model' => 'fixture', 'status' => 'completed',
            'prompt' => 'Fixture', 'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345', 'title' => 'Lenovo ThinkPad E14', 'brand' => 'Lenovo', 'model' => 'ThinkPad E14',
            'description' => 'Verified English description.', 'translations' => $translations,
            'status' => 'pending_review', 'category' => 'laptops', 'product_type' => 'laptop', 'condition' => 'new',
            'specifications' => [], 'sources' => [['url' => 'https://shop.example/e14', 'title' => 'E14']], 'image_urls' => [],
            'primary_source_url' => 'https://shop.example/e14', 'specifications_reconciled_source_url' => 'https://shop.example/e14',
            'gallery_status' => 'complete', 'images_staged_at' => now()]);
    }
}
