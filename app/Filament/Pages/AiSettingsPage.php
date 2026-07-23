<?php

namespace App\Filament\Pages;

use App\Services\Ai\AiSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use UnitEnum;

class AiSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'AI';

    protected static ?string $title = 'Модель OpenAI';

    protected static ?int $navigationSort = 101;

    protected string $view = 'filament.pages.ai-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(AiSettings $settings): void
    {
        $this->form->fill([
            'model' => $settings->model(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Модель OpenAI')
                    ->description('API-ключ берётся только из OPENAI_API_KEY в .env. Модель используется для диалога, исследований, поиска и проверки фото. Голос остаётся на AI_TRANSCRIPTION_MODEL из .env.')
                    ->schema([
                        Select::make('model')
                            ->label('Модель')
                            ->options(AiSettings::MODELS)
                            ->placeholder('Из .env')
                            ->helperText('Пусто — используются модели, заданные в .env.')
                            ->searchable(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(AiSettings $settings): void
    {
        $data = $this->form->getState();
        $settings->saveModel($data['model'] ?? null);

        try {
            Artisan::call('queue:restart');
        } catch (Throwable) {
            // No queue running in this environment — nothing to restart.
        }

        Notification::make()
            ->title('Настройки AI сохранены')
            ->body('Новая модель применится к следующим задачам в очереди.')
            ->success()
            ->send();
    }
}
