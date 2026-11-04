# Импорт товаров

Приложение для асинхронного импорта товаров из XLSX. Backend использует PHP, Slim и Doctrine ORM, frontend — Angular Material и NgRx. Данные хранятся в PostgreSQL, задачи обрабатываются через Symfony Messenger и RabbitMQ.

В интерфейсе доступны вход по JWT, список товаров с серверными фильтрами и пагинацией, карточка с атрибутами и каруселью изображений, прогресс импорта и отчёт об ошибках строк.

## Требования

Для запуска нужны Docker с Linux containers и Docker Compose v2. На Windows подходит Docker Desktop. PHP, Composer и Node.js на хосте для запуска через Docker не требуются.

Для проверочных скриптов нужны Python 3.13 и Docker; для браузерных проверок — Node.js 24, npm и Microsoft Edge либо Playwright Chromium headless shell. При первой сборке и установке тестовых инструментов требуется доступ к реестрам пакетов и образов.

Версии образов и зависимостей закреплены в Dockerfile и lock-файлах. Подробности: [версии](docs/versions.md), [переменные окружения](docs/environment.md).

## Первый запуск

Команды ниже выполняются в PowerShell из корня клонированного репозитория. Команда `docker` должна быть доступна в PATH, Docker Engine должен быть запущен.

```powershell
docker version
docker info --format '{{.OSType}}'

# Создаёт .env только при его отсутствии и генерирует отдельные секреты.
if (!(Test-Path .env)) {
    $settings = Get-Content .env.example -Raw
    foreach ($key in @('POSTGRES_PASSWORD', 'RABBITMQ_DEFAULT_PASS', 'JWT_SECRET')) {
        $bytes = New-Object byte[] 48
        $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
        $rng.GetBytes($bytes)
        $rng.Dispose()
        $value = [Convert]::ToBase64String($bytes)
        $settings = [regex]::Replace($settings, '(?m)^' + $key + '=.*$', $key + '=' + $value)
    }
    [IO.File]::WriteAllText((Join-Path (Get-Location) '.env'), $settings, (New-Object Text.UTF8Encoding $false))
}

docker compose config --quiet
docker compose build app worker nginx
docker compose up -d --wait --wait-timeout 180 app db rabbitmq nginx
docker compose exec -T app php bin/console migrations:migrate --no-interaction
docker compose exec -T app php bin/console orm:validate-schema
```

Ожидаются тип Engine `linux`, успешное применение миграций и согласованная схема БД. Существующий `.env` сохраняется: проверьте пароли и случайный `JWT_SECRET` длиной не менее 32 байт. Не публикуйте `.env` и вывод `docker compose config` без `--quiet`.

### Демонстрационные данные и вход

Для знакомства с приложением на новой пустой БД можно явно загрузить тестовый каталог:

```powershell
docker compose exec -T --user www-data -e APP_ENV=development app php bin/console fixtures:load
docker compose up -d worker
docker compose ps
Invoke-RestMethod http://127.0.0.1:8080/api/health
```

Fixtures создают **48 демонстрационных товаров, 96 атрибутов, 48 связей с тестовыми изображениями и одного пользователя**. Изображения — искусственные цветные образцы, а не фотографии из вашего XLSX. Данные не загружаются автоматически; загрузчик отказывается изменять непустую БД с другим набором записей. Подробнее: [fixtures](docs/fixtures.md).

Откройте [страницу входа](http://127.0.0.1:8080/login). Учётные данные тестового пользователя: `fixture-user` / `password`. Используйте этот публичный пароль только для локальной демонстрации. Для установки без демонстрационных товаров требуется отдельное создание пользователя; интерфейса регистрации и команды создания пользователя сейчас нет.

После запуска `app`, `db`, `rabbitmq` и `nginx` должны быть healthy, `worker` — running. Health endpoint возвращает `status: ok`. У worker нет отдельного healthcheck; его работу можно проверить по состоянию задач и логам.

## Импорт и просмотр товаров

1. На странице `/import` выберите XLSX и нажмите «Начать импорт». Максимальный размер по умолчанию — 10 MiB. Сервер проверяет расширение, MIME и структуру книги. После принятия возвращается задача со статусом `queued`.
2. На `/import/:jobId` отображаются состояние выбранной задачи, счётчики и постраничный отчёт ошибок. Обновление выполняется примерно раз в две секунды после предыдущего ответа. Остановка обновления экрана не отменяет задачу.
3. Worker обрабатывает задачи последовательно. Пока он занят предыдущим импортом, новая задача остаётся в очереди с нулевыми счётчиками. Место в очереди и список других задач в интерфейсе не отображаются.
4. Список товаров поддерживает фильтры по названию и цене. Карточка показывает основные поля, атрибуты и изображения. Фильтры сохраняются при возврате к списку.
5. Переход «К товарам» из отчёта сохраняет контекст выбранного импорта. Отчёт в карточке относится ко всей выбранной задаче, а не обязательно к этому товару.

В книге должен быть один непустой лист. Формулы в импортируемых полях не вычисляются. Невалидная строка не останавливает обработку остальных. Повторный импорт обновляет товары по `external_code`, заменяет атрибуты и актуализирует изображения без дублирования. При конкурентном обновлении одного кода побеждает последняя успешно зафиксированная транзакция.

Изображения извлекаются из колонок «Доп. поле: Ссылка на упаковку» и «Доп. поле: Ссылки на фото». Ошибка скачивания записывается как предупреждение и не отменяет сохранение товара. Успех загрузки зависит от доступности внешнего источника. Подробности: [формат XLSX](docs/import-format.md), [изображения](docs/image-downloader.md).

## API

- [Swagger UI](http://127.0.0.1:8080/docs/) — интерактивная документация с локальными ресурсами.
- [OpenAPI JSON](http://127.0.0.1:8080/docs/openapi.json) — контракт API; исходный файл: [docs/openapi.json](docs/openapi.json).
- `/api/health` и `/api/auth/login` доступны без авторизации. Товары и импорты требуют Bearer JWT. Задачи и отчёты доступны только владельцу.

Для проверки в Swagger выполните `POST /api/auth/login`, скопируйте `accessToken` и вставьте в Authorize **без префикса Bearer**. После этого можно выполнять запросы товаров и загружать XLSX через `POST /api/imports`. Авторизация Swagger сохраняется только в памяти страницы.

JSON использует camelCase. Денежные значения передаются десятичными строками, timestamps — в UTC. Ошибки имеют форму `{error: {code, message, details}}`. Заголовок `X-Request-ID` связывает ответ с серверным журналом. Подробнее: [API-документация](docs/api-documentation.md), [авторизация](docs/auth.md).

## Обновление, остановка и диагностика

```powershell
# Применить изменения frontend.
docker compose up -d --build --no-deps nginx

# Пересобрать и запустить все сервисы.
docker compose up -d --build --wait --wait-timeout 180

# Логи и состояние очереди.
docker compose logs --tail=100 worker app
docker compose exec -T app php bin/console imports:queue-stats

# Остановить и снова запустить существующие контейнеры.
docker compose stop
docker compose start

# Удалить контейнеры и сеть, сохранив данные.
docker compose down
```

Nginx отдаёт готовую frontend-сборку: после изменения исходников её нужно пересобрать и обновить страницу через `Ctrl+F5`. Если менялась схема БД, выполните миграции перед обработкой импортов.

Обычный `down` сохраняет volumes БД, RabbitMQ, загрузок и изображений. **`docker compose down -v` удаляет данные**. Смена `COMPOSE_PROJECT_NAME` выбирает другой набор volumes; смена паролей в `.env` не меняет учётные данные уже созданной БД или брокера. Если порт 8080 занят, измените `HTTP_PORT` в `.env` и используйте новый порт в адресах.

Остановка worker оставляет новые задачи в очереди. При перезапуске возможна повторная доставка сообщения; lease и сохранённые результаты строк защищают счётчики от двойного учёта. Очередь поддерживает ограниченные повторные попытки и failed transport. Подробнее: [worker](docs/import-worker.md), [восстановление](docs/import-recovery.md).

## Проверки

Из корня проекта:

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-docs.py
python scripts/check-e2e.py
python scripts/check-workflow.py
python scripts/check-docker-build.py
```

Ожидается код завершения 0. `check` включает PHPStan, проверку стиля, PHPUnit, проверки схемы и fixtures, frontend lint, typecheck, тесты и сборку. `test` выполняет миграции, проверку схемы, backend- и frontend-тесты, а также повторную загрузку fixtures в изолированной БД. Браузерные проверки покрывают вход, импорт, отчёт, список и карточку с настоящими API, PostgreSQL и RabbitMQ.

Скрипты создают изолированные тестовые окружения и очищают их после выполнения. Не запускайте одновременно две копии одной проверки: `check` и `test` используют общий тестовый проект. Скрипты поддерживают параметр `--docker` для явного пути к Docker CLI. Makefile содержит эквивалентные цели, но Make не обязателен.

Для проверки только frontend при установленных Node.js 24 и npm:

```powershell
npm ci --prefix frontend
npm run check --prefix frontend
```

Браузерные скрипты при необходимости устанавливают Playwright в локальный `tmp/`; проверка документации также создаёт Python virtual environment. По умолчанию используется Edge. Настройка Chromium headless shell описана в [CI](docs/ci.md). Дополнительные инструкции: [тестирование](docs/testing.md), [e2e](docs/e2e-testing.md).

## Ограничения

- Нет интерфейса редактирования и удаления товаров, экспорта, регистрации, обновления JWT и администрирования очереди. CRUD хранения проверяется через Repository/Service tests.
- Прерванный HTTP-ответ загрузки не означает, что задача не была принята. Не отправляйте файл повторно автоматически без проверки результата.
- Нет outbox, автоматического reconciler и очистки файлов, оставшихся после аварии. Полная потеря broker volume или файлов загрузки требует операторского восстановления.
- Загрузка изображений ограничена размером, временем, разрешением и правилами безопасности URL. Нагрузочные пределы для больших каталогов отдельно не измерены.

[Архитектура](docs/architecture.md) · [Переменные окружения](docs/environment.md) · [Схема БД](docs/schema.md) · [Формат XLSX](docs/import-format.md)
