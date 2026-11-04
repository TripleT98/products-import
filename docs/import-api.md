# Статус, отчёт, limiter и health


## HTTP-контракт

- `GET /api/imports/{id}` — JWT, только владелец. Возвращает публичные counters/status/timestamps/fatalError; totalRows может быть null до scan. Нет owner ID, private filename, lease token или server paths. Ответ не кешируется.
- `GET /api/imports/{id}/issues?page=1&limit=50` — тот же ownership; SQL LIMIT/OFFSET, стабильный id ASC. limit 1–100, page 1–2147483647; пустая/повторная/дробная/массивная пагинация →400, дальняя страница →пустые items с актуальным COUNT. Пустой отчёт имеет totalPages=0. Nullable поля сохраняются в JSON.
- Неправильный UUID →400. Отсутствующая и чужая задачи →одинаковые404, без токена →401. Данные хранятся PostgreSQL и читаются заново при polling/после restart.
- `GET /api/health` публичен:200 `{"status":"ok","dependencies":{"database":"ready","broker":"ready"}}` только после SELECT1 и AMQP connect. При сбое любой зависимости →503 стандартного ErrorResponse, без credentials/DSN/paths/trace. Cache-Control:no-store, server X-Request-ID. Health не проверяет очередь/consumer/schema version и не заменяет миграции. Необязательный старый /api/_diagnostics остаётся под APP_DIAGNOSTICS_ENABLED.

## Общее ограничение загрузок

`IMPORT_RATE_LIMIT=5`, `IMPORT_RATE_WINDOW_SECONDS=60` в .env.example и Compose app/worker. Допустимы 1–10000 попыток, окно1–3600 секунд. Миграция Version20261102000200 создаёт import_rate_limits: один счётчик/окно на существующего пользователя, FK CASCADE, ORM read model участвует в schema validation.

ImportController после JWT identity, до чтения upload, вызывает отдельный service/repository. Ключ — ID из проверенной авторизации; X-User-ID/X-Forwarded-For не участвуют. Атомарный PostgreSQL UPSERT сериализует изменения по одному пользователю. Фиксированное окно начинается с первой попытки с точностью до секунды; отклонённые попытки не продлевают окно. Истёкшее окно сбрасывается при следующей попытке, отдельный sweeper не нужен. На пользователя хранится одна строка, без бесконечного журнала запросов.

Учитываются все авторизованные POST, дошедшие до контроллера, включая невалидные файлы. Отказ nginx/PHP до контроллера и 401 не расходуют этот счётчик. Шестая попытка →429 IMPORT_RATE_LIMITED, ErrorResponse details=[], Retry-After — положительное целое число секунд. Счётчик сохраняется между FPM-процессами и restart приложения. Сбой хранилища не отключает limiter: запрос завершается безопасным503. DB lock_timeout2s/statement_timeout3s ограничивают ожидание конкурентов. Нет ограничения login/GET, анти-DDoS или обещания скользящего окна.

## Проверить самостоятельно

PowerShell, Docker Desktop Linux Engine и Python; основное .env/данные не нужны:

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-import-api.py
python scripts/check-upload.py
```

Каждая команда завершается с кодом0 и PASS. Первые две:361 PHPUnit-тест, миграции/schema/lifecycle/fixtures, frontend; check дополнительно Composer strict/PHPStan5/Fixer/ESLint/build. Последняя сохраняет загрузка XLSX validation/broker regression с явным тестовым бюджетом100 вместо5, чтобы проверить все invalid cases. Это не production bypass; отдельный API импорта runner использует5/60.

API импорта runner поднимает product-import-api-check без опубликованных портов: production nginx/FPM/worker, actual cURL, настоящий PG/RabbitMQ, управляемые synthetic XLSX/HTTP images. Проверяет queued→processing→completed_with_errors и рост processedRows через HTTP; pages/error+warning;401/400/404; отсутствие лишних полей по OpenAPI; restart app и сохранение status/issues/limiter; шестой429/Retry-After; независимого владельца; поддельные headers; 10 concurrent requests →ровно5 разрешённых; сброс истёкшего окна; stop/start DB/broker →503 менее10s→200 и сохранённый отчёт. Истечение окна в тесте задаётся SQL fixture без ожидания минуты.

PG использует собственный disposable named volume, чтобы пережить stop/start (tmpfs базовых worker/загрузка XLSX runner при stop теряет данные). Runner удаляет только проверенный отдельный test-проект и его volumes в finally. Итог: tmp/verification-import-api.json; свидетельство выполненного прогона: локальный отчёт проверки. Оригинальный XLSX и внешние URL не используются.

Health задаёт connect_timeout2s для PG, statement_timeout1000ms для SELECT, AMQP connect/read/write/rpc2s. Отдельные пробы последовательные; проверенный бюджет failed HTTP probe — менее10 секунд с Docker exec overhead. Задержки DNS/ОС и произвольная внешняя сеть не профилировались.

Рабочие контейнеры на8080 автоматически не обновлены; для них нужны rebuild, новая миграция и уже описанные [JWT/fixtures](auth.md). UI polling ещё не добавлен. Hard-kill/recovery/two-consumer сценарии остаются восстановление.
