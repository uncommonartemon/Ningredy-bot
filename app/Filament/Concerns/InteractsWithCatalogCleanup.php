<?php

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\Maintenance\CatalogCleanupService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

trait InteractsWithCatalogCleanup
{
    #[Locked]
    public ?string $cleanupPlanToken = null;

    protected function catalogCleanupAction(string $preset): Action
    {
        [$name, $label] = match ($preset) {
            'catalog' => ['clearCatalog', 'Очистить каталог'],
            'drafts' => ['clearDrafts', 'Очистить черновики'],
            'test_data' => ['clearTestData', 'Общая очистка для тестов'],
            'attributes' => ['clearAttributes', 'Очистить характеристики'],
            'attribute_groups' => ['clearAttributeGroups', 'Очистить группы'],
        };

        return Action::make($name)
            ->label(__($label))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->authorize(fn (): bool => $this->cleanupAdministrator() !== null)
            ->requiresConfirmation()
            ->modalHeading(__($label))
            ->modalDescription(__('Будут удалены все записи указанного раздела, независимо от фильтров и выбранной страницы. Отменить удаление нельзя.'))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel(__('Очистить'))
            ->modalCancelActionLabel(__('Отмена'))
            ->closeModalByClickingAway(false)
            ->mountUsing(function () use ($preset): void {
                $actor = $this->requireCleanupAdministrator();
                $plan = app(CatalogCleanupService::class)->prepare($preset, $this->catalogCleanupOptions($preset), $actor);
                if ($this->cleanupPlanToken) {
                    Cache::forget($this->cleanupPlanCacheKey($this->cleanupPlanToken));
                }
                $this->cleanupPlanToken = (string) Str::uuid();
                Cache::put($this->cleanupPlanCacheKey($this->cleanupPlanToken), $plan, now()->addMinutes(15));
            })
            ->modalContent(fn () => view('filament.maintenance.cleanup-preview', ['plan' => $this->catalogCleanupPlan($preset)]))
            ->modalSubmitAction(fn (Action $action): Action => $action->disabled(
                ($plan = $this->catalogCleanupPlan($preset)) === null || filled($plan['blockers'] ?? []),
            ))
            ->action(function () use ($preset): void {
                $actor = $this->requireCleanupAdministrator();
                $plan = $this->catalogCleanupPlan($preset);
                if (! $plan) {
                    Notification::make()->title(__('Подтверждение устарело'))
                        ->body(__('Откройте очистку заново, чтобы увидеть актуальный состав.'))->warning()->send();

                    return;
                }

                try {
                    $result = app(CatalogCleanupService::class)->execute($plan, $actor);
                } catch (AuthorizationException $exception) {
                    throw $exception;
                } catch (ValidationException $exception) {
                    Notification::make()->title(__('Очистка не выполнена'))
                        ->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();

                    return;
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->title(__('Не удалось завершить очистку'))
                        ->body(__('Проверьте журнал операций и обновите страницу перед повтором.'))->danger()->persistent()->send();

                    return;
                }

                Cache::forget($this->cleanupPlanCacheKey($this->cleanupPlanToken));
                $this->cleanupPlanToken = null;
                if (method_exists($this, 'flushCachedTableRecords')) {
                    $this->flushCachedTableRecords();
                    $this->resetPage();
                }
                $notification = Notification::make()->title(__('Очистка завершена'))
                    ->body(__('Удалено записей: :count. Настройки, пользователи и журнал операций сохранены.', ['count' => array_sum($result['deleted'] ?? [])]))
                    ->success();
                if (filled($result['cleanup_warning'] ?? null)) {
                    $notification->title(__('База очищена; очистка файлов требует проверки'))
                        ->body($result['cleanup_warning'])->warning()->persistent();
                }
                $notification->send();
            });
    }

    protected function catalogCleanupOptions(string $preset): array
    {
        return ['dictionaries' => false, 'recipes_history' => false];
    }

    protected function catalogCleanupPlan(string $preset): ?array
    {
        if (! $this->cleanupPlanToken || ! ($actor = $this->cleanupAdministrator())) {
            return null;
        }
        $plan = Cache::get($this->cleanupPlanCacheKey($this->cleanupPlanToken));

        return is_array($plan) && ($plan['preset'] ?? null) === $preset
            && ($plan['actor_id'] ?? null) === $actor->id
            && ($plan['expires_at'] ?? 0) > now()->getTimestamp() ? $plan : null;
    }

    protected function cleanupAdministrator(): ?User
    {
        $user = Filament::auth()->user();
        $user = $user instanceof User ? $user->fresh() : null;

        return $user && $user->canAccessPanel(Filament::getPanel('admin')) ? $user : null;
    }

    protected function requireCleanupAdministrator(): User
    {
        return $this->cleanupAdministrator() ?? throw new AuthorizationException;
    }

    private function cleanupPlanCacheKey(string $token): string
    {
        return 'filament:catalog-cleanup:'.$token;
    }
}
