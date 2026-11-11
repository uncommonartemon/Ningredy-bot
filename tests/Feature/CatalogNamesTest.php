<?php

namespace Tests\Feature;

use App\Ai\Agents\CatalogTranslationAgent;
use App\Jobs\TranslateCatalogNames;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Services\Catalog\CatalogNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Operator, 2026-11-11, with a screenshot of the filters: Czech group names between Ukrainian
 * characteristics. Groups had an English and a Czech name only, and each kind of name was
 * translated its own way. One service now finds and fills every name that lacks a language.
 */
class CatalogNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_kind_of_name_lacking_a_language_is_found_and_filled_by_one_request(): void
    {
        $this->everythingNamed();
        $group = AttributeGroup::query()->create(['name' => 'Connectivity', 'translations' => ['cs' => 'Konektivita']]);
        $typed = AttributeDefinition::query()->create(['key' => 'keyboard_language', 'label' => 'Язык клавиатуры', 'data_type' => 'text']);
        $category = Category::query()->create(['name' => 'Мониторы', 'slug' => 'monitors', 'is_active' => true]);
        $category->translations()->create(['locale' => 'cs', 'name' => 'Monitory']);

        $missing = app(CatalogNames::class)->missing();
        $this->assertSame(['en', 'uk'], $missing["group.{$group->id}"]['locales']);
        $this->assertSame(['en', 'cs', 'uk'], $missing["definition.{$typed->id}"]['locales']);
        $this->assertSame(['en', 'uk'], $missing["category.{$category->id}"]['locales']);

        $requests = [];
        CatalogTranslationAgent::fake(function (string $prompt) use (&$requests): array {
            $requests[] = json_decode($prompt, true)['items'];

            return ['translations' => collect(end($requests))->flatMap(fn (array $item): array => array_map(
                fn (string $locale): array => ['locale' => $locale, 'id' => $item['id'], 'text' => "{$item['text']} [{$locale}]"], $item['locales']))->all()];
        })->preventStrayPrompts();

        $filled = app(CatalogNames::class)->fill();

        // An English name is its own English name, so the model was not asked for it.
        $this->assertCount(1, $requests);
        $asked = collect($requests[0])->keyBy('id');
        $this->assertSame(['uk'], $asked["group.{$group->id}"]['locales']);
        $this->assertSame('group_name', $asked["group.{$group->id}"]['kind']);
        $this->assertSame(['en', 'cs', 'uk'], $asked["definition.{$typed->id}"]['locales']);
        $this->assertSame('category_name', $asked["category.{$category->id}"]['kind']);
        $this->assertSame(7, $filled);
        $this->assertSame(['cs' => 'Konektivita', 'en' => 'Connectivity', 'uk' => 'Connectivity [uk]'], $group->fresh()->translations);
        $this->assertSame('Язык клавиатуры [uk]', $typed->fresh()->translations['uk']);
        $this->assertSame(['cs' => 'Monitory', 'en' => 'Мониторы [en]', 'uk' => 'Мониторы [uk]'],
            $category->fresh()->translations->pluck('name', 'locale')->all());
        $this->assertSame([], app(CatalogNames::class)->missing());
        $this->assertSame('catalog_names', AiRun::query()->latest('id')->first()->activity['stage']);
    }

    public function test_names_the_model_left_unfilled_are_not_asked_again_until_the_admin_asks(): void
    {
        $this->everythingNamed();
        AttributeGroup::query()->create(['name' => 'Connectivity', 'translations' => ['cs' => 'Konektivita']]);
        $calls = 0;
        CatalogTranslationAgent::fake(function () use (&$calls): array {
            $calls++;

            return ['translations' => []];
        })->preventStrayPrompts();

        app(CatalogNames::class)->fill();
        app(CatalogNames::class)->fill();
        $this->assertSame(1, $calls);

        (new TranslateCatalogNames(askAgain: true))->handle(app(CatalogNames::class));
        $this->assertSame(2, $calls);
    }

    public function test_a_failed_request_leaves_the_names_alone_and_the_schedule_queues_work_only_when_needed(): void
    {
        $this->everythingNamed();
        Queue::fake();
        $this->artisan('catalog:translate-names')->assertSuccessful();
        Queue::assertNothingPushed();

        $group = AttributeGroup::query()->create(['name' => 'Connectivity', 'translations' => ['cs' => 'Konektivita']]);
        $this->artisan('catalog:translate-names')->assertSuccessful();
        Queue::assertPushed(TranslateCatalogNames::class, 1);

        CatalogTranslationAgent::fake(fn () => throw new RuntimeException('provider down'))->preventStrayPrompts();
        (new TranslateCatalogNames)->handle(app(CatalogNames::class));
        $this->assertSame(['cs' => 'Konektivita', 'en' => 'Connectivity'], $group->fresh()->translations);
        $this->assertSame('failed', AiRun::query()->latest('id')->value('status'));
    }

    public function test_the_starter_characteristics_get_their_own_names_instead_of_the_first_cards(): void
    {
        AttributeDefinition::query()->where('key', 'ram')->update(['translations' => json_encode(['en' => 'Capacity', 'cs' => 'Kapacita', 'ru' => 'Память'])]);

        (require database_path('migrations/2026_11_11_090000_canonical_names_for_starter_attributes.php'))->up();

        $this->assertSame(['en' => 'Memory', 'cs' => 'Operační paměť', 'ru' => 'Память', 'uk' => 'Оперативна пам’ять'],
            AttributeDefinition::query()->where('key', 'ram')->firstOrFail()->translations);
    }

    /** Gives every name of the starter catalog its languages, so a test sees only what it adds. */
    private function everythingNamed(): void
    {
        CatalogTranslationAgent::fake(fn (string $prompt): array => ['translations' => collect(json_decode($prompt, true)['items'])
            ->flatMap(fn (array $item): array => array_map(
                fn (string $locale): array => ['locale' => $locale, 'id' => $item['id'], 'text' => $item['text']], $item['locales']))->all()]);
        app(CatalogNames::class)->fill();
        $this->assertSame([], app(CatalogNames::class)->missing());
        AiRun::query()->delete();
    }
}
