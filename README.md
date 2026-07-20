# Ningredy

Каталог электроники на Laravel 13: Telegram принимает команды администратора, Laravel AI понимает запрос, ищет сначала в локальном каталоге, при необходимости исследует интернет и выполняет только разрешённые серверные операции.

## Быстрый запуск на Windows

Один раз установите зависимости и подготовьте БД:

```powershell
composer install
npm install
php artisan migrate --force
php artisan storage:link
npm run build
```

Заполните `.env`: `ADMIN_PASSWORD`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_ALLOWED_USER_IDS` и `OPENAI_API_KEY`. Пустой `TELEGRAM_ALLOWED_USER_IDS` блокирует всех пользователей.

Для обычного запуска откройте:

```powershell
.\start-ningredy.bat
```

BAT автоматически и с перезапуском поднимает:

- Laravel на `http://127.0.0.1:8000`;
- очередь `assistant` для текстовых AI-команд;
- очередь `voice` для транскрибации;
- очередь `media` для поиска, Vision-проверки и сохранения фото;
- ngrok и синхронизацию Telegram webhook при смене URL.

Автозапуск после входа в Windows устанавливается через `install-ningredy-autostart.bat`.

## Как работает бот

Примеры сообщений:

- `Найди у нас ноутбук с 8 GB RAM` — поиск по товарам, вариантам и характеристикам локальной БД.
- `Найди в интернете Lenovo Legion RTX 4070 серого цвета` — web research и черновик с источниками.
- `Измени цену Lenovo Legion на 900` — поиск точного товара и аудируемое изменение.
- `Скрой товар #12` — деактивация без удаления данных и фото.
- `Удалить товар #12` — только подготовка операции; физическое удаление выполняется после отдельной кнопки подтверждения.
- `/url` — мгновенно показать текущий ngrok URL и адрес каталога без вызова AI.
- Голосовое сообщение — качественная транскрибация, затем тот же сценарий, что для текста.
- Обычный вопрос вроде `Как дела?` — обычный ответ без вызова инструментов.

Поиск в интернете не публикует товар сразу. Бот присылает описание и кнопку добавления. После добавления отдельная media-очередь скачивает несколько кандидатов, Vision выбирает подходящие фото, сервер перекодирует их в WebP и сохраняет в `storage/app/public/products/{id}`. В Telegram отправляется уже проверенный локальный файл. WebP-перекодирование удаляет EXIF и служебные метаданные. При скрытии товара файлы остаются, при удалении товара удаляются автоматически.

Повторный запуск одной job безопасен: изменение с теми же входными данными не выполняется второй раз.

## Админ-панель

Filament доступен на `http://127.0.0.1:8000/admin`. В панели можно:

- искать, фильтровать, создавать и редактировать товары и варианты;
- управлять характеристиками, источниками и локальными изображениями;
- активировать и скрывать товары;
- проверять AI-черновики, AI-запуски, операции и Telegram-журнал;
- хранить ngrok URL и вручную нажать `Set webhook`.

Публичный каталог использует ровную адаптивную панель из трёх блоков. На странице товара при двух и более фотографиях автоматически включается Swiper со стрелками, пагинацией, клавиатурой и миниатюрами.

Доступ разрешён только пользователю с `is_admin=true` и именем из `ADMIN_NAME`.

## Полезные команды

```powershell
php artisan telegram:set-webhook
php artisan telegram:sync-ngrok
php artisan telegram:sync-ngrok --watch
php artisan queue:failed
php artisan queue:retry <uuid>
php artisan queue:restart
php artisan test --compact
vendor\bin\pint --test
```

Для разработки с Vite, логами и всеми worker-ами:

```powershell
composer dev
```

## Linux

Шаблон `deploy/systemd/ningredy-worker@.service` запускает отдельные worker-ы:

```bash
sudo systemctl enable --now ningredy-worker@assistant.service
sudo systemctl enable --now ningredy-worker@voice.service
sudo systemctl enable --now ningredy-worker@media.service
sudo systemctl enable --now ningredy-worker@default.service
```

Для production предпочтительны PostgreSQL/MySQL и Redis. Текущий SQLite настроен на WAL и подходит для одного небольшого локального сервера.

## Границы AI

AI не получает shell или прямой SQL-доступ. Инструменты Laravel валидируют параметры, сервер выполняет операции, а `ai_operations` хранит аудит. Интернет-страницы считаются недоверенными данными. Удаление требует отдельного Telegram-подтверждения.
