<?php

namespace App\Filament\Schemas;

use App\Filament\Support\PastedProductAttributes;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Services\Products\ManualProductAttributes;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;

class ProductAttributeFields
{
    public static function make(): Repeater
    {
        return Repeater::make('attributes')->relationship()->label(__('Характеристики'))
            ->hintAction(fn (Repeater $component): Action => self::pasteAction($component))
            ->schema([
                Hidden::make('_category_default')->dehydrated(false),
                Select::make('attribute_definition_id')->label(__('Характеристика'))
                    ->options(fn (): array => RelatedCatalogForms::attributeOptions())->searchable()->preload()->live()
                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                    ->required(fn (Get $get): bool => filled($get('value')))
                    ->afterStateUpdated(function (Get $get, Set $set, $state, $old): void {
                        if ((string) $state === (string) $old) {
                            return;
                        }
                        if (filled($old)) {
                            $set('value', null);
                            $set('unit', AttributeDefinition::find($state)?->default_unit);
                        } elseif (blank($get('unit'))) {
                            $set('unit', AttributeDefinition::find($state)?->default_unit);
                        }
                        $set('_category_default', false);
                    })
                    ->createOptionForm(RelatedCatalogForms::attribute())
                    ->createOptionUsing(fn (array $data): int => AttributeDefinition::create($data)->id)
                    ->columnSpan(['default' => 1, '@md' => 2]),
                TextInput::make('value')->label(__('Значение'))
                    ->placeholder(__('Введите или выберите из подсказок'))
                    ->datalist(fn (Get $get): array => app(ManualProductAttributes::class)->suggestions($get('attribute_definition_id'), $get('value')))
                    ->live(debounce: 400)
                    ->required(fn (?AttributeValue $record): bool => $record?->exists ?? false)
                    ->maxLength(2000)->columnSpan(['default' => 1, '@md' => 2]),
                TextInput::make('unit')->label(__('Единица'))->maxLength(24)->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => $set('_category_default', false))
                    ->placeholder(__('Необязательно'))
                    ->columnStart(['default' => 1, '@md' => 5]),
            ])
            // A nested variant can be narrow even on a desktop-size viewport.
            ->gridContainer()->columns(['default' => 1, '@md' => 5, '@2xl' => 5])
            ->default(fn (Get $get): array => app(ManualProductAttributes::class)->defaults($get('../../category_id')))
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): ?array => app(ManualProductAttributes::class)->normalize($data))
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): ?array => app(ManualProductAttributes::class)->normalize($data))
            ->addActionLabel(__('Добавить характеристику'))->columnSpanFull();
    }

    private static function pasteAction(Repeater $component): Action
    {
        return Action::make('pasteAttributes')->label(__('catalog-form.paste_from_excel'))->icon('heroicon-o-clipboard-document-list')
            ->modalHeading(__('catalog-form.paste_heading'))->modalSubmitActionLabel(__('catalog-form.paste_submit'))
            ->schema([
                Textarea::make('pasted_attributes')->label(__('catalog-form.paste_table'))->required()->rows(10)->maxLength(100000)
                    ->placeholder("CPU\tIntel Core i7\nRAM\t16\tGB")
                    ->helperText(__('catalog-form.paste_help')),
                Toggle::make('replace_filled')->label(__('catalog-form.replace_filled'))->default(false)
                    ->helperText(__('catalog-form.replace_help')),
                Toggle::make('create_missing')->label(__('catalog-form.create_missing'))->default(false)
                    ->helperText(__('catalog-form.create_help')),
            ])
            ->action(function (array $data, $livewire) use ($component): void {
                try {
                    $result = app(PastedProductAttributes::class)->merge(
                        $data['pasted_attributes'], $component->getRawState() ?? [],
                        $data['replace_filled'] ?? false, $data['create_missing'] ?? false,
                    );
                } catch (ValidationException $exception) {
                    $statePath = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
                    throw ValidationException::withMessages([
                        $statePath.'.pasted_attributes' => $exception->errors()['pasted_attributes'],
                    ]);
                }
                $component->rawState($result['rows']);
                $component->callAfterStateUpdated();
                Notification::make()->title(__('catalog-form.pasted'))
                    ->body(__('catalog-form.paste_result', array_diff_key($result, ['rows' => true])))
                    ->success()->send();
            });
    }
}
