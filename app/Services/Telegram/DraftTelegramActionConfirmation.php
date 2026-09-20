<?php

namespace App\Services\Telegram;

use App\Services\Ai\AiSettings;

/** Describes existing paid operations; never starts them or changes their limits. */
class DraftTelegramActionConfirmation
{
    public function describe(string $callback): ?array
    {
        if (! preg_match('/^draft:(restage|continue-search|findmore|source-retrain|retrain|new-search|enhance-photo|replace-photo|delete-photo):\d+(?::\d+)?$/', $callback, $match)) {
            return null;
        }

        [$title, $description] = match ($match[1]) {
            'restage' => ['Заменить всю галерею?', 'Будет запущен поиск других фото с исключением прежних источников. Текущие фото сохраняются до успешной замены.'],
            'continue-search' => ['Продолжить поиск?', 'Поиск получит новый бюджет. Найденное ранее сохраняется.'],
            'findmore' => ['Найти дополнительные фото?', 'Текущие фото останутся, бот попробует дополнить галерею.'],
            'source-retrain', 'retrain' => ['Переобучить источник?', 'Агент заново проверит способ сбора фото. Рабочий рецепт не заменяется непроверенным.'],
            'new-search' => ['Запустить уточнённый поиск?', 'Это новый поиск товара и фото. Старый черновик не меняется и не отклоняется.'],
            'enhance-photo' => ['Улучшить выбранное фото?', 'Это платная обработка изображения через AI, а не поиск оригинала. Цена зависит от модели и изображения.'],
            'replace-photo' => ['Найти замену выбранному фото?', 'Бот поищет другой кадр. Остальные фото остаются.'],
            'delete-photo' => ['Удалить выбранное фото?', 'Фото будет удалено из этого черновика. Остальные останутся. Действие бесплатное.'],
        };

        $budget = ! in_array($match[1], ['enhance-photo', 'delete-photo'], true)
            ? app(AiSettings::class)->maxSearchCostUsd() : null;
        if ($budget !== null) {
            $description .= $budget > 0
                ? '\nНастроенный бюджет этого запуска: $'.number_format($budget, 2).'. Это не фиксированная цена; итог зависит от вызовов AI.'
                : '\nДенежный лимит в настройках отключён. Запуск платный.';
        }

        return [
            'title' => $title,
            'description' => str_replace('\\n', "\n", $description),
            'budget' => $budget,
            'label' => $match[1] === 'delete-photo' ? 'Да, удалить это фото' : 'Подтверждаю платный запуск',
        ];
    }
}
