<?php

namespace Tests\Feature;

use App\Filament\Pages\DatabaseBrowser;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\ProductSourceAttempt;
use App\Models\ProductSourceDomain;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Ai\AiSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * Presses every button of the admin panel on a seeded test database: table row,
 * header and bulk actions of every resource, header actions of its create/edit/
 * view pages, its relation managers, and the custom pages. A button may hide,
 * be disabled for the record or ask for input; it must never throw.
 */
class AdminEveryButtonTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $report = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'url' => '']]),
            'https://api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-6-luna', 'object' => 'model']]]),
            '*' => Http::response('', 200),
        ]);
        config(['services.admin.name' => 'audit-admin']);
        $this->actingAs(User::factory()->create(['name' => 'audit-admin', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->seedEverything();
    }

    public function test_every_admin_button_works_or_explains_why_it_cannot(): void
    {
        // Every safe button everywhere first, then every destructive one, so a
        // delete never removes the record another button still needs.
        foreach ([false, true] as $destructivePass) {
            foreach (Filament::getPanel('admin')->getResources() as $resource) {
                $model = $resource::getModel();
                $record = $model::query()->latest((new $model)->getKeyName())->first();
                $pages = collect($resource::getPages())->map(fn ($registration) => $registration->getPage());
                foreach ($pages as $page) {
                    if ($record !== null && (is_subclass_of($page, EditRecord::class) || is_subclass_of($page, ViewRecord::class))) {
                        $params = ['record' => $record->getRouteKey()];
                        foreach ($resource::getRelations() as $relation) {
                            if (is_string($relation)) {
                                $this->pressTable($relation, ['ownerRecord' => $record, 'pageClass' => $page], null,
                                    class_basename($page).'/'.class_basename($relation), $destructivePass);
                            }
                        }
                        if (! $destructivePass && is_subclass_of($page, EditRecord::class)) {
                            $this->press(class_basename($page), 'save', fn () => Livewire::test($page, $params)->call('save'));
                        }
                        $this->pressHeader($page, $params, class_basename($page), $destructivePass);
                    } elseif (is_subclass_of($page, CreateRecord::class) && ! $destructivePass) {
                        $this->pressHeader($page, [], class_basename($page), false);
                        $this->press(class_basename($page), 'create (empty form)', fn () => Livewire::test($page)->call('create'));
                    }
                }
                foreach ($pages as $page) {
                    if (is_subclass_of($page, ListRecords::class)) {
                        $this->pressHeader($page, [], class_basename($page), $destructivePass);
                        $this->pressTable($page, [], $model::query()->latest((new $model)->getKeyName())->first(), class_basename($page), $destructivePass);
                    }
                }
            }
            foreach (Filament::getPanel('admin')->getPages() as $page) {
                $this->pressHeader($page, [], class_basename($page), $destructivePass);
                if (! $destructivePass && method_exists($page, 'save')) {
                    $this->press(class_basename($page), 'save', fn () => Livewire::test($page)->call('save'));
                }
            }
        }

        // The database browser links rows to their resource once a table with one is open.
        $this->press('DatabaseBrowser', 'resource (products table)', fn (): Testable => Livewire::test(DatabaseBrowser::class)
            ->set('databaseTable', 'products')->callAction('resource'));

        $this->writeReport();
        $errors = array_values(array_filter($this->report, fn (array $row): bool => str_starts_with($row[2], 'ERROR')));
        $this->assertSame([], $errors, 'Buttons that threw: '.json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->assertGreaterThan(40, count($this->report));
    }

    /** Every action a table knows: row actions, header/toolbar actions and bulk actions. */
    private function pressTable(string $component, array $params, ?Model $record, string $where, bool $destructivePass): void
    {
        try {
            $table = Livewire::test($component, $params)->instance()->getTable();
        } catch (Throwable $e) {
            $this->report[] = [$where, '(open)', 'ERROR '.$this->short($e)];

            return;
        }
        $record ??= $table->getQuery()?->first();
        $rowActions = $this->flatten($table->getRecordActions());
        if ($record === null && $rowActions !== []) {
            $this->report[] = [$where, '(row buttons)', 'no record left to press on'];
        }
        foreach ($table->getFlatActions() + $table->getFlatBulkActions() as $name => $action) {
            if ($this->destructive($action) !== $destructivePass || ($record === null && (isset($rowActions[$name]) || $action->isBulk()))) {
                continue;
            }
            $this->press($where, (string) $name, function () use ($component, $params, $action, $name, $record, $rowActions): Testable {
                $test = Livewire::test($component, $params);
                if ($action->isBulk()) {
                    return $test->selectTableRecords($record ? [$record->getKey()] : [])->callAction(TestAction::make($name)->table()->bulk());
                }

                return $test->callAction(isset($rowActions[$name]) && $record ? TestAction::make($name)->table($record) : TestAction::make($name)->table());
            });
        }
    }

    private function pressHeader(string $page, array $params, string $where, bool $destructivePass): void
    {
        try {
            $actions = $this->flatten(Livewire::test($page, $params)->instance()->getCachedHeaderActions());
        } catch (Throwable $e) {
            $this->report[] = [$where, '(open)', 'ERROR '.$this->short($e)];

            return;
        }
        foreach ($actions as $name => $action) {
            if ($this->destructive($action) === $destructivePass) {
                $this->press($where, (string) $name, fn (): Testable => Livewire::test($page, $params)->callAction($name));
            }
        }
    }

    private function press(string $where, string $button, callable $click): void
    {
        try {
            $test = $click();
            $outcome = $test instanceof Testable && $test->errors()->isNotEmpty()
                ? 'asks for input: '.implode(', ', array_slice($test->errors()->keys(), 0, 3))
                : 'ok';
        } catch (Throwable $e) {
            $message = $this->short($e);
            $outcome = match (true) {
                str_contains($message, 'is visible'), str_contains($message, 'not visible'), str_contains($message, 'hidden') => 'hidden for this record',
                str_contains($message, 'is enabled'), str_contains($message, 'disabled') => 'disabled for this record',
                default => 'ERROR '.$message,
            };
        }
        $this->report[] = [$where, $button, $outcome];
    }

    /** @param array<Action|ActionGroup> $actions @return array<string, Action> */
    private function flatten(array $actions): array
    {
        $flat = [];
        foreach ($actions as $action) {
            if ($action instanceof ActionGroup) {
                $flat += $this->flatten($action->getActions());
            } elseif ($action instanceof Action) {
                $flat[$action->getName()] = $action;
            }
        }

        return $flat;
    }

    private function destructive(Action $action): bool
    {
        return (bool) preg_match('/delete|destroy|purge|cleanup|clear|reject/i', (string) $action->getName());
    }

    private function short(Throwable $e): string
    {
        return mb_substr(class_basename($e).': '.preg_replace('/\s+/', ' ', $e->getMessage()), 0, 220);
    }

    private function writeReport(): void
    {
        $lines = array_map(fn (array $row): string => str_pad($row[0], 44).' '.str_pad($row[1], 26).' '.$row[2], $this->report);
        @file_put_contents(storage_path('logs/admin-every-button.txt'), implode(PHP_EOL, $lines).PHP_EOL);
    }

    private function seedEverything(): void
    {
        $category = Category::query()->where('slug', 'laptops')->first() ?? Category::create(['name' => 'Laptops', 'product_type_affinity' => 'laptop']);
        $brand = Brand::create(['name' => 'Audit Brand', 'is_active' => true]);
        Brand::create(['name' => 'Audit Brand 2', 'is_active' => true]);
        AttributeGroup::create(['name' => 'Audit group 2']);
        ProductSourceDomain::create(['domain' => 'example.org', 'agent_hint' => 'Second audit hint.']);
        $group = AttributeGroup::create(['name' => 'Audit group']);
        AttributeDefinition::create(['label' => 'Audit attribute', 'data_type' => 'text', 'attribute_group_id' => $group->id]);
        $product = Product::create(['category_id' => $category->id, 'brand_id' => $brand->id, 'title' => 'Audit laptop',
            'product_type' => 'laptop', 'status' => 'published', 'is_active' => true]);
        $product->variants()->create(['condition' => 'new', 'currency' => 'CZK', 'price' => 100, 'stock_status' => 'unknown',
            'quantity' => 0, 'is_default' => true, 'is_active' => true]);
        Storage::disk('public')->put('audit.jpg', 'jpeg');
        $product->media()->create(['disk' => 'public', 'path' => 'audit.jpg', 'width' => 1600, 'height' => 1200,
            'mime_type' => 'image/jpeg', 'checksum' => hash('sha256', 'audit-product')]);

        $update = TelegramUpdate::create(['update_id' => 777001, 'telegram_user_id' => '1', 'chat_id' => '1',
            'text' => 'audit laptop', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'audit',
            'status' => 'completed', 'prompt' => 'audit', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '1',
            'title' => 'Audit laptop', 'brand' => 'Audit Brand', 'model' => 'AUDIT-1', 'condition' => 'new',
            'product_type' => 'laptop', 'category' => 'laptops', 'status' => 'pending_review',
            'description' => 'An audit fixture laptop.', 'specifications' => [], 'sources' => [], 'image_urls' => [],
            'primary_source_url' => 'https://example.com/audit', 'specifications_reconciled_source_url' => 'https://example.com/audit']);
        $draft->media()->create(['disk' => 'public', 'path' => 'audit.jpg', 'width' => 1600, 'height' => 1200,
            'mime_type' => 'image/jpeg', 'checksum' => hash('sha256', 'audit'), 'verification_status' => 'verified']);
        foreach ([777002] as $extra) {
            $u = TelegramUpdate::create(['update_id' => $extra, 'telegram_user_id' => '1', 'chat_id' => '1', 'text' => 'audit laptop 2', 'payload' => [], 'status' => 'completed']);
            $r = AiRun::create(['telegram_update_id' => $u->id, 'provider' => 'openai', 'model' => 'audit', 'status' => 'completed', 'prompt' => 'audit', 'started_at' => now(), 'completed_at' => now()]);
            $second = ProductDraft::create(['telegram_update_id' => $u->id, 'ai_run_id' => $r->id, 'requested_by_telegram_user_id' => '1', 'title' => 'Audit laptop 2', 'brand' => 'Audit Brand', 'model' => 'AUDIT-2', 'condition' => 'new', 'product_type' => 'laptop', 'category' => 'laptops', 'status' => 'pending_review', 'description' => 'A second audit laptop.', 'specifications' => [], 'sources' => [], 'image_urls' => []]);
            $second->media()->create(['disk' => 'public', 'path' => 'audit.jpg', 'width' => 1600, 'height' => 1200, 'mime_type' => 'image/jpeg', 'checksum' => hash('sha256', 'audit-2'), 'verification_status' => 'verified']);
        }
        // Buttons that only show in a given state get that state.
        app(AiSettings::class)->saveOpenAiApiKey('sk-audit-key');
        AiOperation::create(['telegram_update_id' => $update->id, 'tool' => 'audit_tool', 'action' => 'audit_operation', 'idempotency_key' => 'audit-op', 'status' => 'completed', 'payload' => []]);

        $recipe = ProductGalleryRecipe::create(['domain' => 'example.com', 'path_pattern' => '*', 'status' => 'active',
            'recipe' => ['gallery_selector' => '.good']]);
        ProductGalleryRecipeVersion::create(['product_gallery_recipe_id' => $recipe->id, 'domain' => 'example.com',
            'product_url' => 'https://example.com/item', 'trigger' => 'test', 'status' => 'promoted', 'provider' => 'openai',
            'model' => 'audit', 'recipe' => ['gallery_selector' => '.good'], 'result' => ['candidate_count' => 3]]);
        ProductSourceDomain::create(['domain' => 'example.com', 'agent_hint' => 'Audit hint.']);
        ProductSourceAttempt::create(['telegram_update_id' => $update->id, 'product_draft_id' => $draft->id,
            'product_url' => 'https://example.com/audit', 'domain' => 'example.com', 'actor' => 'server',
            'phase' => 'gallery_preflight', 'action' => 'check_source_access', 'status' => 'completed']);
    }
}
