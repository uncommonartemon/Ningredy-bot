# Telegram AI-оператор

`ServerAssistantAgent` принимает обычный текст и результат транскрибации голосовых сообщений.

## Маршрутизация

1. Обычное «найди» сначала вызывает `SearchCatalog`.
2. Поиск учитывает название, модель, бренд, тип, варианты, SKU, CPU, GPU, RAM, цвет и цену. Скрытые товары тоже доступны администратору.
3. Явное «найди в интернете» либо отсутствие локального совпадения вызывает `ResearchProduct`.
4. Интернет-результат создаёт черновик. Публикация происходит только по кнопке Telegram или через Filament.
5. После публикации queue `media` скачивает кандидатов, Vision выбирает до трёх изображений, сервер сохраняет WebP и отправляет проверенное фото в Telegram.

## Очереди

- `assistant` — диалог, локальный поиск, web research и операции каталога;
- `voice` — загрузка Telegram OGG и `gpt-4o-transcribe`;
- `media` — discovery, скачивание, Vision и WebP;
- `default` — остальные Laravel jobs.

Для SQLite задан `retry_after=420`, а timeout самой длинной job равен 240 секундам. AI-операции имеют idempotency key, поэтому retry не повторяет уже завершённое изменение.

## Разрешённые действия

Без дополнительной кнопки агент может редактировать безопасные поля, варианты и цену, а также активировать или скрывать товар. Все изменения записываются в `ai_operations`.

Физическое удаление выполняется в два шага: агент находит один точный товар и создаёт `awaiting_confirmation`, затем Laravel удаляет запись только после отдельной inline-кнопки того же пользователя в том же чате. Вместе с товаром удаляется его локальная папка изображений.

AI не получает shell или SQL-доступ. Инструменты Laravel валидируют параметры и выполняют ограниченные операции.

## systemd

```bash
sudo cp deploy/systemd/ningredy-worker@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ningredy-worker@assistant.service
sudo systemctl enable --now ningredy-worker@voice.service
sudo systemctl enable --now ningredy-worker@media.service
sudo systemctl enable --now ningredy-worker@default.service
```

После deploy используйте `php artisan migrate --force`, `php artisan optimize` и `php artisan queue:restart`.
