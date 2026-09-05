<?php

namespace App\Filament\Pages;

use App\Services\BotHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use UnitEnum;

/**
 * The screen someone opens to answer "is it working".
 *
 * Everything here was previously visible only in the console window that gets
 * closed within a minute of handing the bot over, or not visible anywhere at
 * all - whether Telegram can actually reach us is a question only Telegram can
 * answer, and nothing was asking it.
 */
class BotStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'Состояние';

    protected static ?string $title = 'Состояние бота';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.bot-status';

    /** @var array<int, array{key: string, label: string, state: string, detail: string, hint: string}> */
    public array $checks = [];

    public ?string $smokeOutput = null;

    public function mount(): void
    {
        $this->refreshChecks();
    }

    public function refreshChecks(): void
    {
        $this->checks = app(BotHealth::class)->checks();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Обновить')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->refreshChecks()),
            Action::make('smoke')
                ->label('Полная проверка')
                ->icon(Heroicon::OutlinedBeaker)
                ->requiresConfirmation()
                ->modalHeading('Полная проверка')
                ->modalDescription(
                    'Запустит настоящий браузер и один небольшой платный запрос к AI, чтобы проверить весь путь '
                    .'сбора фотографий. Занимает около минуты.',
                )
                ->modalSubmitActionLabel('Запустить')
                ->action(function (): void {
                    try {
                        // The same command the console offers, so the two can
                        // never drift into disagreeing about what "healthy"
                        // means. The person who was handed the bot should not
                        // need a terminal to ask the question.
                        Artisan::call('bot:smoke');
                        $this->smokeOutput = trim(Artisan::output());
                        $this->refreshChecks();

                        Notification::make()
                            ->title(str_contains($this->smokeOutput, 'FAIL') ? 'Проверка нашла проблемы' : 'Проверка пройдена')
                            ->status(str_contains($this->smokeOutput, 'FAIL') ? 'warning' : 'success')
                            ->send();
                    } catch (Throwable $exception) {
                        $this->smokeOutput = $exception->getMessage();

                        Notification::make()->title('Проверку не удалось запустить')->danger()->send();
                    }
                }),
        ];
    }
}
