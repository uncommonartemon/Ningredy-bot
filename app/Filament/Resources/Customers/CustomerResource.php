<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\LocalizedResource as Resource;
use App\Models\Customer;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The shop's customers. They sign up themselves through a group's invitation link; here
 * the admin moves them between groups, closes an account or sets a new password.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Клиенты';

    protected static ?string $modelLabel = 'клиент';

    protected static ?string $pluralModelLabel = 'клиенты';

    protected static string|UnitEnum|null $navigationGroup = 'Клиенты';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->dense()->columns(1)->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make(__('Клиент'))
                    ->schema([
                        TextInput::make('name')->label(__('Имя'))->required()->maxLength(120),
                        TextInput::make('email')->label(__('Почта'))->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                        TextInput::make('company')->label(__('Компания'))->maxLength(160),
                        TextInput::make('phone')->label(__('Телефон'))->tel()->maxLength(40),
                        Textarea::make('note')->label(__('Заметка для себя'))->rows(3)->maxLength(2000)->columnSpanFull(),
                    ])
                    ->gridContainer()->columns(['default' => 1, '@md' => 2])
                    ->columnSpan(['default' => 1, 'lg' => 2]),
                Section::make(__('Доступ'))
                    ->schema([
                        Select::make('customer_group_id')->label(__('Группа'))->relationship('group', 'name')
                            ->preload()->searchable()->placeholder(__('Без группы: обычные цены')),
                        Toggle::make('is_active')->label(__('Может входить'))->default(true),
                        // There is no mail server, so a forgotten password is replaced here.
                        TextInput::make('password')->label(__('Новый пароль'))->password()->revealable()
                            ->minLength(8)->maxLength(255)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): ?string => $operation === 'create' ? null
                                : __('Оставьте пустым, чтобы не менять. Новый пароль сообщите клиенту сами.')),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('group'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Имя'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('Почта'))->searchable()->copyable(),
                TextColumn::make('company')->label(__('Компания'))->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('group.name')->label(__('Группа'))->badge()->placeholder(__('Без группы'))->sortable(),
                ToggleColumn::make('is_active')->label(__('Может входить'))->sortable(),
                TextColumn::make('last_login_at')->label(__('Последний вход'))->since()->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label(__('Зарегистрирован'))->dateTime('d.m.Y H:i')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('customer_group_id')->label(__('Группа'))->relationship('group', 'name'),
                TernaryFilter::make('is_active')->label(__('Может входить')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
