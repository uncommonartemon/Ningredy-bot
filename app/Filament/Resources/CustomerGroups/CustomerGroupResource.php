<?php

namespace App\Filament\Resources\CustomerGroups;

use App\Filament\Resources\CustomerGroups\Pages\CreateCustomerGroup;
use App\Filament\Resources\CustomerGroups\Pages\EditCustomerGroup;
use App\Filament\Resources\CustomerGroups\Pages\ListCustomerGroups;
use App\Filament\Resources\LocalizedResource as Resource;
use App\Models\CustomerGroup;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Groups of customers with prices of their own (operator, 2026-11-11). A group's
 * invitation link is how its customers sign up; its prices are set on each product.
 */
class CustomerGroupResource extends Resource
{
    protected static ?string $model = CustomerGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Группы клиентов';

    protected static ?string $modelLabel = 'группа клиентов';

    protected static ?string $pluralModelLabel = 'группы клиентов';

    protected static string|UnitEnum|null $navigationGroup = 'Клиенты';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->dense()->columns(1)->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make(__('Группа'))
                    ->schema([
                        TextInput::make('name')->label(__('Название'))->required()->maxLength(255)->unique(ignoreRecord: true)
                            ->helperText(__('Клиент видит это название в своём кабинете.')),
                        TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0)->required(),
                        Textarea::make('note')->label(__('Заметка для себя'))->rows(3)->maxLength(2000)->columnSpanFull(),
                        TextInput::make('invite_url')->label(__('Ссылка для регистрации'))
                            ->formatStateUsing(fn (?CustomerGroup $record): ?string => $record?->inviteUrl())
                            ->readOnly()->dehydrated(false)->copyable()->columnSpanFull()
                            ->visible(fn (?CustomerGroup $record): bool => $record !== null)
                            ->helperText(__('Отправьте её клиенту: по ней он сам регистрируется и сразу попадает в эту группу. Открытой регистрации на сайте нет.')),
                    ])
                    ->gridContainer()->columns(['default' => 1, '@md' => 2])
                    ->columnSpan(['default' => 1, 'lg' => 2]),
                Section::make(__('Доступ'))
                    ->schema([
                        Toggle::make('is_active')->label(__('Группа действует'))->default(true)
                            ->helperText(__('Клиенты выключенной группы видят обычные цены.')),
                        Toggle::make('registration_open')->label(__('Регистрация по ссылке открыта'))->default(true)
                            ->helperText(__('Выключите, когда все нужные клиенты зарегистрировались.')),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['customers', 'prices']))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('Название'))->searchable()->sortable(),
                TextColumn::make('customers_count')->label(__('Клиенты'))->badge()->sortable(),
                TextColumn::make('prices_count')->label(__('Своих цен'))->badge()->color('gray')->sortable(),
                TextColumn::make('invite_code')->label(__('Ссылка для регистрации'))
                    ->formatStateUsing(fn (CustomerGroup $record): string => $record->inviteUrl())
                    ->copyable()->copyableState(fn (CustomerGroup $record): string => $record->inviteUrl())
                    ->limit(48)->color('gray'),
                ToggleColumn::make('registration_open')->label(__('Регистрация открыта')),
                ToggleColumn::make('is_active')->label(__('Действует'))->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerGroups::route('/'),
            'create' => CreateCustomerGroup::route('/create'),
            'edit' => EditCustomerGroup::route('/{record}/edit'),
        ];
    }
}
