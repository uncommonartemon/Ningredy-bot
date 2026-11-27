<?php

namespace Tests\Feature;

use App\Ai\Agents\CatalogTranslationAgent;
use App\Ai\Tools\UpdateVariant;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Jobs\TranslateCatalogNames;
use App\Jobs\TranslateProductText;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\BotHealth;
use App\Services\Catalog\CatalogNames;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use App\Services\Products\CardTranslationState;
use App\Services\Products\CatalogLocales;
use App\Services\Products\CatalogTextTranslator;
use App\Services\Products\ProductDraftWorkflow;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Decision of 2026-11-27: a buyer reads a card only in the shop's languages. Everything a card
 * shows that is text is asked for translation by itself, whoever wrote it; what is not text by its
 * nature (model names, codes, numbers with units, abbreviations) stays as written. Each test here
 * replays a shape of data that a real catalog left untranslated for good.
 */
class CardTranslationTest extends TestCase
{
    use RefreshDatabase;

    private int $updates = 0;

    public function test_a_fully_translated_card_lacks_nothing(): void
    {
        $product = $this->translatedProduct();

        $this->assertSame([], app(CardTranslationState::class)->missing($product));
        $this->assertNull(app(CardTranslationState::class)->pendingNote($product));
        $this->assertSame([], app(CardTranslationState::class)->pendingProductIds());
    }

    /** The storefront showed Ukrainian, the cards were translated into Czech only: every Ukrainian page stood in English. */
    public function test_a_storefront_language_is_always_one_the_cards_are_translated_into(): void
    {
        CatalogLocales::save(['cs'], ['cs', 'uk'], 'cs');

        $this->assertSame(['cs' => 'Čeština', 'uk' => 'Українська'], CatalogLocales::contentTranslations());
        $product = $this->translatedProduct();
        $product->update(['translations' => ['cs' => $product->translations['cs']]]);
        $this->assertSame(['uk'], app(CardTranslationState::class)->missing($product->fresh())['product.title']['locales']);
    }

    /** Live: «Matte black with a red Viper logo and silver name plate» stood on Czech and Ukrainian pages as the configuration and its colour. */
    public function test_a_configuration_named_by_its_colour_is_asked_once_and_shown_in_the_visitors_language(): void
    {
        $product = $this->translatedProduct();
        $colour = 'Matte black with a red Viper logo and silver name plate';
        $variant = $product->variants()->sole();
        $variant->update(['name' => $colour, 'color' => $colour]);
        $requests = $this->fakeTranslator();

        app(CatalogTextTranslator::class)->translate($product->fresh());

        $this->assertCount(1, $requests->items);
        $this->assertSame([$colour], array_column($requests->items[0], 'text'));
        $translations = $variant->fresh()->translations;
        $this->assertSame("CS: {$colour}", $translations['cs']['name']);
        $this->assertSame("UK: {$colour}", $translations['uk']['color']);
        // The product's own copies in the configuration stay.
        $this->assertSame('Notebook Lenovo ThinkPad E14', $translations['cs']['title']);
        $this->get(route('products.show', $product))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.variants.0.translations.uk.color', "UK: {$colour}")
            ->where('product.variants.0.translations.cs.name', "CS: {$colour}")
            ->etc());

        // A new colour is new text: the old translation goes with it and the colour is asked again.
        $variant->fresh()->update(['color' => 'Silver']);
        $this->assertArrayNotHasKey('color', $variant->fresh()->translations['cs']);
        $this->assertSame('CS: '.$colour, $variant->fresh()->translations['cs']['name']);
        $this->assertArrayHasKey("variant.{$variant->id}.color", app(CardTranslationState::class)->missing($product->fresh()));
    }

    /**
     * What is not text stays as written in every language; Cyrillic is always text. Live: «16гб» and
     * «один» typed by hand, and «16 ГБ» which the old rule took for a code (two capitals).
     */
    public function test_the_rule_of_what_is_text_keeps_codes_numbers_and_abbreviations_and_sends_every_word(): void
    {
        foreach (['16 GB', '3840 x 2160', 'DDR5', 'DDR5-4800', 'i7-1360P', 'MZ-V8V1T0', 'RTX 4060', 'IPS', 'USB 3.2', '1 TB', '65 W'] as $code) {
            $this->assertFalse(CardTranslationState::isText($code), $code);
        }
        foreach (['Yes', 'No', 'Up to 4400 MT/s', 'Supported', 'Black', 'Intel Core i7', 'один', '16гб', '16 ГБ', 'ДА', 'НЕТ'] as $word) {
            $this->assertTrue(CardTranslationState::isText($word), $word);
        }
        // A short mixed-case unit or abbreviation reads as a word to the rule: it is sent, and the
        // translator's answer as written counts. That costs a few words, never an untranslated text.
        foreach (['2.4 GHz', 'NVMe', 'PCIe 4.0'] as $mixed) {
            $this->assertTrue(CardTranslationState::isText($mixed), $mixed);
            $this->assertTrue(CardTranslationState::acceptable('spec_value', $mixed, $mixed, 'cs'), $mixed);
        }

        $product = $this->translatedProduct();
        $definition = fn (string $key): int => AttributeDefinition::query()->create(['key' => $key, 'label' => $key, 'data_type' => 'text',
            'translations' => ['en' => $key, 'cs' => $key, 'uk' => $key]])->id;
        $typed = $product->attributes()->create(['attribute_definition_id' => $definition('test_backlight'), 'value' => 'ДА']);
        $withUnit = $product->variants()->sole()->attributes()->create(['attribute_definition_id' => $definition('test_capacity'), 'value' => '512', 'unit' => 'ГБ']);
        $latinUnit = $product->attributes()->create(['attribute_definition_id' => $definition('test_cache'), 'value' => '512', 'unit' => 'GB']);

        $missing = app(CardTranslationState::class)->missing($product->fresh());

        $this->assertSame('ДА', $missing["value.{$typed->id}"]['text']);
        // A bare number is read with its unit; a unit in Cyrillic is a word and goes with its number.
        $this->assertSame('512 ГБ', $missing["value.{$withUnit->id}"]['text']);
        $this->assertArrayNotHasKey("value.{$latinUnit->id}", $missing);
    }

    /**
     * A translator's answer that cannot be a translation is not stored, so the text is asked again
     * after the wait: the English description sent back, Cyrillic for Czech, Russian for Ukrainian.
     */
    public function test_an_answer_that_cannot_stand_as_a_translation_is_not_stored_and_is_asked_again(): void
    {
        $product = $this->translatedProduct();
        $product->update(['translations' => ['cs' => ['title' => 'Notebook Lenovo ThinkPad E14'], 'uk' => ['title' => 'Ноутбук Lenovo ThinkPad E14']]]);
        $value = $product->attributes()->sole();
        $value->update(['translations' => null]);
        CatalogTranslationAgent::fake(fn (): array => ['translations' => [
            ['locale' => 'cs', 'id' => 'product.description', 'text' => 'Fast laptop for work.'],
            ['locale' => 'uk', 'id' => 'product.description', 'text' => 'Быстрый ноутбук для работы.'],
            ['locale' => 'cs', 'id' => "value.{$value->id}", 'text' => 'До 16 ГБ'],
            ['locale' => 'uk', 'id' => "value.{$value->id}", 'text' => 'До 16 ГБ'],
        ]])->preventStrayPrompts();
        $translator = app(CatalogTextTranslator::class);

        $translator->translate($product->fresh());

        $this->assertArrayNotHasKey('description', $product->fresh()->translations['cs']);
        $this->assertArrayNotHasKey('description', $product->fresh()->translations['uk']);
        $this->assertSame(['uk' => 'До 16 ГБ'], $value->fresh()->translations);
        $this->assertSame([$product->id], $translator->pendingProductIds());
        $this->assertSame([], $translator->dueProductIds());
        $this->travel(6)->hours();
        $this->travel(1)->minutes();
        $this->assertSame([$product->id], $translator->dueProductIds());
    }

    /**
     * Answers stored before that check stood on the pages as translations: «один» typed by hand and
     * sent back unchanged for Czech, a category named in Russian for Ukrainian. They count as none
     * and are asked for again, so the catalog as it is converges by itself.
     */
    public function test_a_stored_translation_that_could_not_stand_as_one_is_asked_for_again(): void
    {
        $product = $this->translatedProduct();
        $value = $product->attributes()->sole();
        $value->update(['value' => 'один', 'translations' => ['cs' => 'один', 'uk' => 'один']]);
        $category = $product->category;
        $category->translations()->where('locale', 'uk')->update(['name' => 'Видеокарты']);

        $missing = app(CardTranslationState::class)->missing($product->fresh());
        $this->assertSame(['cs'], $missing["value.{$value->id}"]['locales']);
        $this->assertSame(['uk'], $missing["category.{$category->id}"]['locales']);

        $this->fakeTranslator();
        (new TranslateProductText($product->id))->handle(app(CatalogTextTranslator::class));

        $this->assertSame(['cs' => "CS: value.{$value->id}", 'uk' => 'один'], $value->fresh()->translations);
        $this->assertSame('UK: Ноутбуки', $category->translations()->where('locale', 'uk')->value('name'));
        $this->assertSame([], app(CardTranslationState::class)->missing($product->fresh()));
    }

    /** A card with a long description was sent in one request; a cut-off answer failed it whole, every six hours. */
    public function test_a_long_card_is_asked_in_parts_and_one_failed_part_does_not_lose_the_others(): void
    {
        $product = $this->translatedProduct();
        $product->update(['description' => str_repeat('A long and careful description of this laptop. ', 140), 'translations' => null]);
        $product->attributes()->sole()->update(['translations' => null]);
        $requests = $this->fakeTranslator(fail: fn (array $items): bool => in_array('product.description', array_column($items, 'id'), true));

        (new TranslateProductText($product->id))->handle(app(CatalogTextTranslator::class));

        $this->assertGreaterThan(1, count($requests->items));
        $product->refresh();
        $this->assertSame('UK: Lenovo ThinkPad E14 laptop', $product->translations['uk']['title']);
        $this->assertArrayNotHasKey('description', $product->translations['uk']);
        $this->assertSame('CS: Up to 16 GB', $product->attributes()->sole()->translations['cs']);
        $this->assertDatabaseHas('ai_runs', ['status' => 'failed']);
        $this->assertSame([$product->id], app(CardTranslationState::class)->pendingProductIds());
    }

    /** The same product comes in again with a card whose check of facts never ran: its paid translations were wiped. */
    public function test_the_same_product_coming_in_again_keeps_the_translations_it_had(): void
    {
        Storage::fake('public');
        Queue::fake();
        $workflow = app(ProductDraftWorkflow::class);
        $first = $workflow->approve($this->draft([
            'cs' => ['title' => 'Notebook Lenovo ThinkPad E14', 'description' => 'Rychlý notebook do práce.',
                'specifications' => [['key' => 'test_memory', 'name' => 'Paměť', 'value' => 'Až 16 GB']]],
            'uk' => ['title' => 'Ноутбук Lenovo ThinkPad E14', 'description' => 'Швидкий ноутбук для роботи.',
                'specifications' => [['key' => 'test_memory', 'name' => 'Пам’ять', 'value' => 'До 16 ГБ']]],
        ]));
        $first->takeOffSale();

        $again = $workflow->approve($this->draft(null));

        $this->assertSame($first->id, $again->id);
        $again->refresh();
        $this->assertTrue($again->isOnSale());
        $this->assertSame('Rychlý notebook do práce.', $again->translations['cs']['description']);
        $this->assertSame('Ноутбук Lenovo ThinkPad E14', $again->variants()->sole()->translations['uk']['title']);
        $value = AttributeValue::query()->whereHas('definition', fn ($query) => $query->where('key', 'test_memory'))->sole();
        $this->assertSame(['cs' => 'Až 16 GB', 'uk' => 'До 16 ГБ'], $value->translations);
    }

    /**
     * A colour and a brand's country were filter options without any translation, and a value's
     * options took the first value's languages: an older value translated into Czech only hid the
     * Ukrainian a later value had.
     */
    public function test_the_colour_and_country_filters_and_a_values_options_carry_every_language_there_is(): void
    {
        $taiwan = Brand::query()->create(['name' => 'ASUS', 'slug' => 'asus', 'country' => 'Тайвань', 'country_translations' => ['cs' => 'Tchaj-wan', 'uk' => 'Тайвань']]);
        $china = Brand::query()->create(['name' => 'Lenovo', 'slug' => 'lenovo', 'country' => 'Китай', 'country_translations' => ['cs' => 'Čína', 'uk' => 'Китай']]);
        $first = $this->translatedProduct(['title' => 'ASUS Zenbook 14', 'slug' => 'asus-zenbook-14', 'brand_id' => $taiwan->id]);
        $second = $this->translatedProduct(['title' => 'Lenovo Yoga 7', 'slug' => 'lenovo-yoga-7', 'brand_id' => $china->id]);
        $third = $this->translatedProduct(['title' => 'Lenovo Yoga 9', 'slug' => 'lenovo-yoga-9', 'brand_id' => $china->id]);
        $first->variants()->sole()->update(['color' => 'Black', 'translations' => ['cs' => ['color' => 'Černá'], 'uk' => ['color' => 'Чорна']]]);
        $second->variants()->sole()->update(['color' => 'Silver', 'translations' => ['cs' => ['color' => 'Stříbrná'], 'uk' => ['color' => 'Срібна']]]);
        $third->variants()->sole()->update(['color' => 'Silver']);
        $first->attributes()->sole()->update(['translations' => ['cs' => 'Až 16 GB']]);
        $third->attributes()->sole()->update(['value' => 'Up to 32 GB', 'normalized_value' => 'up to 32 gb', 'translations' => ['cs' => 'Až 32 GB', 'uk' => 'До 32 ГБ']]);

        $this->get('/catalog')->assertOk()->assertInertia(function (Assert $page): void {
            $facets = $page->toArray()['props']['facets'];
            $columns = collect($facets['columns'])->keyBy('key');
            $colors = collect($columns['colors']['options'])->keyBy('value');
            $this->assertSame(['cs' => 'Černá', 'uk' => 'Чорна'], $colors['Black']['translations']);
            $this->assertSame(['cs' => 'Stříbrná', 'uk' => 'Срібна'], $colors['Silver']['translations']);
            $countries = collect($columns['countries']['options'])->keyBy('value');
            $this->assertSame(['cs' => 'Tchaj-wan', 'uk' => 'Тайвань'], $countries['Тайвань']['translations']);
            $memory = collect(collect($facets['attributes'])->firstWhere('key', 'test_memory')['options'])->keyBy('value');
            $this->assertSame(['cs' => 'Až 16 GB', 'uk' => 'До 16 ГБ'], $memory['up to 16 gb']['translations']);
        });
    }

    /** The photos of a product were described by its English title, read aloud and shown when a photo does not load. */
    public function test_a_photo_is_described_by_the_title_in_the_visitors_language(): void
    {
        $product = $this->translatedProduct();
        $product->media()->create(['type' => 'image', 'disk' => 'public', 'path' => "products/{$product->id}/1.webp",
            'verification_status' => 'verified', 'alt' => $product->title, 'is_primary' => true]);

        $this->get(route('products.show', $product))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.media.0.alt', null)->etc());
    }

    /** A configuration's name or colour changed from the chat waited for the scheduler; a brand's country for nothing at all. */
    public function test_the_chat_asks_again_when_a_configuration_name_colour_or_a_brands_country_changes(): void
    {
        Queue::fake();
        $product = $this->translatedProduct();
        $variant = $product->variants()->sole();
        $brand = Brand::query()->create(['name' => 'Lenovo', 'slug' => 'lenovo', 'country' => 'China', 'country_translations' => ['cs' => 'Čína']]);

        $result = json_decode((string) (new UpdateVariant($this->ask('Цвет чёрный')))->handle(new Request(['variant_id' => $variant->id, 'color' => 'Black'])), true);
        $this->assertTrue($result['ok']);
        Queue::assertPushed(TranslateProductText::class, fn (TranslateProductText $job): bool => $job->productId === $product->id);

        $service = app(DatabaseOperatorService::class);
        $context = $this->ask('Конфигурация 32 ГБ, страна бренда Китай');
        $service->execute($service->prepare([
            ['action' => 'update', 'table' => 'product_variants', 'selection' => ['ids' => [$variant->id]], 'values' => ['name' => '32 GB / 1 TB']],
            ['action' => 'update', 'table' => 'brands', 'selection' => ['ids' => [$brand->id]], 'values' => ['country' => 'Китай']],
        ], $context), $context);

        Queue::assertPushed(TranslateProductText::class, 2);
        Queue::assertPushed(TranslateCatalogNames::class);
        $this->assertNull($brand->fresh()->country_translations);
    }

    /** A brand's country typed in the admin stood on every product page and in the filters as written. */
    public function test_a_brands_country_is_named_in_the_shop_languages_by_the_names_schedule(): void
    {
        $brand = Brand::query()->create(['name' => 'ASUS', 'slug' => 'asus', 'country' => 'Тайвань']);
        $product = $this->translatedProduct(['brand_id' => $brand->id]);
        $this->assertTrue(app(CardTranslationState::class)->missing($product)["country.{$brand->id}"]['shared']);
        $requests = $this->fakeTranslator();

        app(CatalogNames::class)->fill();

        $this->assertNotEmpty($requests->items);
        $this->assertSame(['cs' => "CS: country.{$brand->id}", 'en' => "EN: country.{$brand->id}", 'uk' => 'UK: Тайвань'],
            collect($brand->fresh()->country_translations)->sortKeys()->all());
        $this->get(route('products.show', $product))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.brand.country_translations.uk', 'UK: Тайвань')->etc());
    }

    /** A name answered in Russian stood on the Ukrainian pages: «Видеокарты» for «Відеокарти». */
    public function test_a_name_answered_in_russian_for_ukrainian_or_in_cyrillic_for_czech_is_not_stored(): void
    {
        $group = AttributeGroup::query()->create(['name' => 'Видеокарты', 'translations' => ['en' => 'Graphics cards']]);
        CatalogTranslationAgent::fake(fn (): array => ['translations' => [
            ['locale' => 'uk', 'id' => "group.{$group->id}", 'text' => 'Видеокарты'],
            ['locale' => 'cs', 'id' => "group.{$group->id}", 'text' => 'Видеокарты'],
        ]])->preventStrayPrompts();

        app(CatalogNames::class)->fill();

        $this->assertSame(['en' => 'Graphics cards'], $group->fresh()->translations);
    }

    /** A new group of characteristics came with a card and waited for the names' schedule; now the card's request names it. */
    public function test_the_cards_translation_job_names_its_new_group_at_once(): void
    {
        $product = $this->translatedProduct();
        $group = AttributeGroup::query()->where('name', 'Memory')->sole();
        $group->update(['translations' => ['en' => 'Memory', 'cs' => 'Paměť']]);
        $this->fakeTranslator();

        (new TranslateProductText($product->id))->handle(app(CatalogTextTranslator::class));

        $this->assertSame('UK: Memory', $group->fresh()->translations['uk']);
        $this->assertSame([], app(CardTranslationState::class)->missing($product->fresh()));
    }

    public function test_the_admin_lists_products_without_a_translation_and_the_status_page_names_them(): void
    {
        $translated = $this->translatedProduct();
        $waiting = $this->translatedProduct(['title' => 'Lenovo Legion 5', 'slug' => 'lenovo-legion-5']);
        $waiting->variants()->sole()->update(['color' => 'Storm grey']);
        $this->admin();

        Livewire::test(ListProducts::class)->set('activeTab', 'untranslated')
            ->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$translated]);
        $health = collect(app(BotHealth::class)->checks())->firstWhere('key', 'translations');
        $this->assertSame('down', $health['state']);
        $this->assertStringContainsString('#'.$waiting->id, $health['detail']);
        $this->assertStringContainsString('чешский: цвет', app(CardTranslationState::class)->describe($waiting));
    }

    /** Saving the product in the admin kept only the fields its form shows: a configuration's translated name and colour went with every save. */
    public function test_saving_the_product_in_the_admin_keeps_and_shows_its_configurations_translated_name_and_colour(): void
    {
        $product = $this->translatedProduct();
        $variant = $product->variants()->sole();
        $variant->update(['name' => 'Storm grey', 'color' => 'Storm grey', 'translations' => [
            'cs' => [...$variant->translations['cs'], 'name' => 'Bouřkově šedá', 'color' => 'Bouřkově šedá'],
            'uk' => [...$variant->translations['uk'], 'name' => 'Грозовий сірий', 'color' => 'Грозовий сірий'],
        ]]);
        $this->admin();

        Livewire::test(EditProduct::class, ['record' => $product->id])->call('save')->assertHasNoFormErrors();

        $translations = $variant->fresh()->translations;
        $this->assertSame(['Bouřkově šedá', 'Bouřkově šedá'], [$translations['cs']['name'], $translations['cs']['color']]);
        $this->assertSame('Грозовий сірий', $translations['uk']['color']);
        $this->assertSame([], app(CardTranslationState::class)->missing($product->fresh()));
    }

    /** The operator presses «Добавить» before the translation is made: the answer says it is on its way. */
    public function test_the_approval_in_telegram_says_the_translation_is_on_its_way_only_while_it_is(): void
    {
        Storage::fake('public');
        Cache::flush();
        Queue::fake();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345']]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $untranslated = $this->draft(null);
        $this->press("draft:add:{$untranslated->id}", 4101);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && str_contains((string) $request['text'], 'добавлен в каталог и активен. 🌐 Перевод на чешский и украинский готовится и появится на сайте сам.'));

        $product = Product::query()->sole();
        $product->update(['translations' => ['cs' => ['title' => 'Notebook', 'description' => 'Popis.'], 'uk' => ['title' => 'Ноутбук', 'description' => 'Опис.']]]);
        AttributeValue::query()->update(['translations' => json_encode(['cs' => 'Až 16 GB', 'uk' => 'До 16 ГБ'])]);
        $product->takeOffSale();
        $again = $this->draft(['cs' => ['title' => 'Notebook', 'description' => 'Popis.'], 'uk' => ['title' => 'Ноутбук', 'description' => 'Опис.']]);
        $this->press("draft:add:{$again->id}", 4102);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && str_contains((string) $request['text'], "Товар #{$product->id} добавлен в каталог и активен. Операция"));
    }

    /**
     * A card whose every text a buyer reads is in Czech and Ukrainian, with its names: a category, a
     * group and a characteristic of its own, so a test changes exactly one thing.
     */
    private function translatedProduct(array $overrides = []): Product
    {
        [$category, $definition] = $this->names();
        $text = ['cs' => ['title' => 'Notebook Lenovo ThinkPad E14', 'description' => 'Rychlý notebook do práce.'],
            'uk' => ['title' => 'Ноутбук Lenovo ThinkPad E14', 'description' => 'Швидкий ноутбук для роботи.']];
        $product = Product::query()->create([...['category_id' => $category->id, 'title' => 'Lenovo ThinkPad E14 laptop', 'slug' => 'lenovo-thinkpad-e14',
            'description' => 'Fast laptop for work.', 'product_type' => 'laptop', 'status' => 'published', 'is_active' => true,
            'published_at' => now(), 'translations' => $text], ...$overrides]);
        $product->variants()->create(['name' => ProductVariant::DEFAULT_NAME, 'currency' => 'CZK', 'price' => 20000, 'is_default' => true,
            'is_active' => true, 'stock_status' => 'in_stock', 'translations' => $text]);
        $product->attributes()->create(['attribute_definition_id' => $definition->id, 'value' => 'Up to 16 GB',
            'translations' => ['cs' => 'Až 16 GB', 'uk' => 'До 16 ГБ']]);

        return $product->fresh();
    }

    /**
     * A category, a group and a characteristic of the test's own, named in every language.
     *
     * @return array{Category, AttributeDefinition}
     */
    private function names(): array
    {
        $category = Category::query()->firstOrCreate(['slug' => 'translated-laptops'], ['name' => 'Ноутбуки', 'is_active' => true, 'product_type_affinity' => 'laptop']);
        foreach (['en' => 'Laptops', 'cs' => 'Notebooky', 'uk' => 'Ноутбуки'] as $locale => $name) {
            $category->translations()->firstOrCreate(['locale' => $locale], ['name' => $name]);
        }
        $group = AttributeGroup::query()->firstOrCreate(['name' => 'Memory'], ['translations' => ['en' => 'Memory', 'cs' => 'Paměť', 'uk' => 'Пам’ять']]);
        $definition = AttributeDefinition::query()->firstOrCreate(['key' => 'test_memory'], ['label' => 'Память', 'data_type' => 'text',
            'is_filterable' => true, 'attribute_group_id' => $group->id, 'translations' => ['en' => 'Memory', 'cs' => 'Paměť', 'uk' => 'Пам’ять']]);

        return [$category, $definition];
    }

    /**
     * Answers every request with each text marked by its language; returns what was asked.
     *
     * @param  (callable(array): bool)|null  $fail  a request this returns true for fails, as a cut-off answer does
     */
    private function fakeTranslator(?callable $fail = null): object
    {
        $requests = new class
        {
            /** @var list<list<array<string, mixed>>> */
            public array $items = [];
        };
        CatalogTranslationAgent::fake(function (string $prompt) use ($requests, $fail): array {
            $items = json_decode($prompt, true)['items'];
            $requests->items[] = $items;
            if ($fail !== null && $fail($items)) {
                throw new \RuntimeException('The answer was cut off.');
            }
            $translations = [];
            foreach ($items as $item) {
                foreach ($item['locales'] as $locale) {
                    // Czech and English are answered in Latin letters, as a translator does.
                    $text = $locale !== 'uk' && preg_match('/\p{Cyrillic}/u', $item['text']) === 1 ? $item['id'] : $item['text'];
                    $translations[] = ['locale' => $locale, 'id' => $item['id'], 'text' => strtoupper($locale).': '.$text];
                }
            }

            return ['translations' => $translations];
        })->preventStrayPrompts();

        return $requests;
    }

    /** A searched card of the same laptop, checked and with a photo, with or without its translations. */
    private function draft(?array $translations): ProductDraft
    {
        $this->names();
        $update = $this->ask('Lenovo ThinkPad E14 21JR0007CK');
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'local', 'model' => 'fixture', 'status' => 'completed',
            'prompt' => 'Fixture', 'started_at' => now(), 'completed_at' => now()]);
        $source = 'https://shop.example/e14';
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '12345',
            'status' => 'pending_review', 'title' => 'Lenovo ThinkPad E14 laptop', 'brand' => 'Lenovo', 'model' => 'ThinkPad E14',
            'description' => 'Fast laptop for work.', 'product_type' => 'laptop', 'category' => 'translated-laptops', 'condition' => 'new',
            'confidence' => 0.9, 'translations' => $translations,
            'specifications' => [['key' => 'mpn', 'name' => 'Part number', 'value' => '21JR0007CK', 'source_url' => $source, 'source_excerpt' => '21JR0007CK'],
                ['key' => 'test_memory', 'name' => 'Memory', 'value' => 'Up to 16 GB', 'source_url' => $source, 'source_excerpt' => 'Up to 16 GB']],
            'sources' => [['title' => 'Shop', 'url' => $source]], 'image_urls' => [], 'primary_source_url' => $source,
            'specifications_reconciled_source_url' => $source, 'gallery_status' => 'complete', 'images_staged_at' => now()]);
        Storage::disk('public')->put("drafts/{$draft->id}/1.webp", 'photo');
        $draft->media()->create(['disk' => 'public', 'path' => "drafts/{$draft->id}/1.webp", 'source_url' => $source.'/1.webp', 'role' => 'primary',
            'mime_type' => 'image/webp', 'width' => 1600, 'height' => 1200, 'checksum' => hash('sha256', $draft->id.'photo'),
            'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);

        return $draft;
    }

    private function press(string $data, int $updateId): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => $updateId, 'callback_query' => [
            'id' => 'callback-'.$updateId, 'from' => ['id' => 12345, 'username' => 'admin'], 'data' => $data,
            'message' => ['message_id' => $updateId, 'chat' => ['id' => 98765]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function admin(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        app()->setLocale('ru');
    }

    private function ask(string $text): TelegramUpdate
    {
        return TelegramUpdate::query()->create(['update_id' => 940000 + (++$this->updates), 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'message_id' => 100 + $this->updates, 'text' => $text, 'payload' => [], 'status' => 'processing']);
    }
}
