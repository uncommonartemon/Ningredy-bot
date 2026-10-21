<?php

namespace App\Filament\Pages;

use App\Services\Admin\DatabaseBrowser as Browser;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use UnitEnum;

class DatabaseBrowser extends LocalizedPage implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Система';

    protected static ?string $navigationLabel = 'Обзор БД';

    protected static ?string $title = 'Обзор БД';

    protected static ?int $navigationSort = 80;

    protected string $view = 'filament.pages.database-browser';

    #[Url(as: 'table', history: true)]
    public string $databaseTable = 'product_source_page_evidence';

    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'search')]
    public $tableSearch = '';

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    private ?Browser $browserService = null;

    private function browser(): Browser
    {
        return $this->browserService ??= app(Browser::class);
    }

    public static function canAccess(): bool
    {
        return Browser::canAccess();
    }

    public function mount(): void
    {
        $this->browser()->columns($this->databaseTable);
        $this->form->fill(['databaseTable' => $this->databaseTable]);
    }

    public function hydrate(): void
    {
        $this->browser()->authorize();
    }

    public function updatedDatabaseTable(): void
    {
        $this->browser()->columns($this->databaseTable);
        $this->tableSearch = '';
        $this->tableSort = null;
        $this->tableFilters = null;
        $this->resetTable();
    }

    public function getTableFiltersSessionKey(): string
    {
        return 'database_browser.'.auth()->id().'.'.$this->databaseTable.'.filters';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('databaseTable')->label(__('Таблица'))
                ->options(fn (): array => $this->browser()->tableOptions())
                ->searchable()->live()->required()
                ->helperText(__('Все фактические таблицы, включая служебные. Просмотр без изменения данных. Секреты и сериализованные служебные payload скрыты сервером.')),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('resource')->label(__('Открыть раздел'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->visible(fn (): bool => $this->browser()->resourceUrl($this->databaseTable) !== null)
            ->url(fn (): ?string => $this->browser()->resourceUrl($this->databaseTable))];
    }

    public function table(Table $table): Table
    {
        $columns = $this->browser()->listColumns($this->databaseTable);

        return $table
            ->heading($this->databaseTable)
            ->description(__('Поиск по показанным полям; точный фильтр — по выбранной колонке. Полное содержимое и связи открываются по строке.'))
            ->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, int|string $recordsPerPage, int $page, ?array $filters) => $this->browser()->records($this->databaseTable, $search, $sortColumn, $sortDirection,
                $recordsPerPage, $page, $filters['field'] ?? []))
            ->columns(array_map(fn (string $column, int $index) => TextColumn::make($column)
                ->label($column)->searchable()->sortable()->limit($column === 'specification_text' ? 100 : 55)
                ->toggleable(isToggledHiddenByDefault: $index > 8), $columns, array_keys($columns)))
            ->filters([Filter::make('field')->label(__('Точное значение'))
                ->schema([
                    Select::make('column')->label(__('Колонка'))->options(array_combine($columns, $columns))->searchable(),
                    TextInput::make('value')->label(__('Значение')),
                ])])
            ->persistFiltersInSession()
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(50)
            ->recordAction('inspect')
            ->recordActions([Action::make('inspect')->label(__('Открыть'))->icon(Heroicon::OutlinedEye)
                ->slideOver()->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel(__('Закрыть'))
                ->modalHeading(fn (): string => $this->databaseTable)
                ->schema(function (array $record): array {
                    $row = $this->browser()->record($this->databaseTable, $record['_locator']);
                    $components = [];
                    foreach ($row as $column => $value) {
                        $entry = TextEntry::make($column)->label($column)->state($value)->copyable()
                            ->url($this->browser()->relatedUrl($this->databaseTable, $column, $value))
                            ->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere; max-height: 65vh; overflow: auto;']);
                        $components[] = mb_strlen($value) > 240 || str_contains($value, "\n")
                            ? Section::make($column)->schema([$entry->hiddenLabel()])->collapsible()->collapsed()->columnSpanFull()
                            : $entry;
                    }

                    return [Section::make(__('Запись'))->compact()->columns(2)->schema($components)];
                }),
                Action::make('resource')->label(__('Открыть раздел'))
                    ->visible(fn (array $record): bool => $this->browser()->resourceUrl($this->databaseTable, $record['id'] ?? null) !== null)
                    ->url(fn (array $record): ?string => $this->browser()->resourceUrl($this->databaseTable, $record['id'] ?? null)),
            ]);
    }
}
