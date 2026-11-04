# Конфигурация окружения

Источник значений по умолчанию — [.env.example](../.env.example), связь с services — [compose.yaml](../compose.yaml). .env локальный и игнорируется Git; не печатайте `docker compose config` с рабочими credentials, используйте `config --quiet`. Для нового запуска сгенерируйте DB/broker/JWT secrets по README. Существующий .env не перезаписывается.

| Переменная | Пример/default | Назначение / ограничение |
|---|---|---|
| `COMPOSE_PROJECT_NAME` | `product-import` | Имя проекта и named volumes. Смена выбирает другие данные. |
| `HTTP_PORT` | `8080` | Порт nginx; bind всегда 127.0.0.1. |
| `APP_ENV` | `production` | production; fixtures/workbook inspection требуют command-scoped development/test. |
| `APP_DIAGNOSTICS_ENABLED` | `false` | Публичный optional /api/_diagnostics; false по умолчанию. /api/health доступен всегда. |
| `DB_HOST` | `db` | PostgreSQL internal connection/bootstrap; host port не публикуется. |
| `DB_PORT` | `5432` | PostgreSQL internal connection/bootstrap; host port не публикуется. |
| `POSTGRES_DB` | `product_import` | PostgreSQL internal connection/bootstrap; host port не публикуется. |
| `POSTGRES_USER` | `product_import` | PostgreSQL internal connection/bootstrap; host port не публикуется. |
| `POSTGRES_PASSWORD` | `change-me-local-db` | Заменить пример; изменение env не меняет пароль существующего PG volume. |
| `RABBITMQ_HOST` | `rabbitmq` | RabbitMQ internal connection/bootstrap; host ports не публикуются. |
| `RABBITMQ_PORT` | `5672` | RabbitMQ internal connection/bootstrap; host ports не публикуются. |
| `RABBITMQ_DEFAULT_USER` | `product_import` | RabbitMQ internal connection/bootstrap; host ports не публикуются. |
| `RABBITMQ_DEFAULT_PASS` | `change-me-local-broker` | Заменить пример; изменение env не меняет credentials существующего broker volume. |
| `RABBITMQ_DEFAULT_VHOST` | `/` | RabbitMQ internal connection/bootstrap; host ports не публикуются. |
| `UPLOAD_DIR` | `/var/app/uploads` | Private XLSX, абсолютный отдельный каталог внутри /var/app/. |
| `IMAGE_DIR` | `/var/app/images` | Абсолютный каталог внутри /var/app/; nginx читает его как /images/. |
| `JWT_SECRET` | `<задать>` | Обязательный случайный секрет >=32 bytes; пустое значение не подходит для login. |
| `JWT_ISSUER` | `product-import` | HS256 JWT metadata; issuer/audience проверяются сервером. |
| `JWT_AUDIENCE` | `product-import-web` | HS256 JWT metadata; issuer/audience проверяются сервером. |
| `JWT_TTL_SECONDS` | `3600` | 1..86400 секунд; без refresh token. |
| `XLSX_MAX_FILE_BYTES` | `10485760` | Положительное целое; shared XLSX upload/worker limit. Bytes/rows/entries согласно имени. |
| `XLSX_MAX_UNCOMPRESSED_BYTES` | `104857600` | Положительное целое; shared XLSX upload/worker limit. Bytes/rows/entries согласно имени. |
| `XLSX_MAX_ZIP_ENTRIES` | `1000` | Положительное целое; shared XLSX upload/worker limit. Bytes/rows/entries согласно имени. |
| `XLSX_MAX_COMPRESSION_RATIO` | `100` | Положительное целое; shared XLSX upload/worker limit. Bytes/rows/entries согласно имени. |
| `XLSX_MAX_DATA_ROWS` | `10000` | Положительное целое; shared XLSX upload/worker limit. Bytes/rows/entries согласно имени. |
| `XLSX_MAX_ROW_NUMBER` | `100001` | Положительное целое; shared XLSX upload/worker limit. Bytes/rows/entries согласно имени. |
| `XLSX_CHUNK_ROWS` | `250` | Положительное целое; shared XLSX upload/worker limit. Bytes/rows/entries согласно имени. |
| `IMAGE_MAX_BYTES` | `5242880` | Лимит downloader; BYTES в bytes, *_MS в ms, остальные — count/pixels. |
| `IMAGE_CONNECT_TIMEOUT_MS` | `3000` | Лимит downloader; BYTES в bytes, *_MS в ms, остальные — count/pixels. |
| `IMAGE_TOTAL_TIMEOUT_MS` | `10000` | Лимит downloader; BYTES в bytes, *_MS в ms, остальные — count/pixels. |
| `IMAGE_MAX_REDIRECTS` | `3` | Лимит downloader; BYTES в bytes, *_MS в ms, остальные — count/pixels. |
| `IMAGE_MAX_PIXELS` | `12000000` | Лимит downloader; BYTES в bytes, *_MS в ms, остальные — count/pixels. |
| `IMAGE_MAX_DIMENSION` | `10000` | Лимит downloader; BYTES в bytes, *_MS в ms, остальные — count/pixels. |
| `IMAGE_MAX_HEADER_BYTES` | `16384` | Лимит downloader; BYTES в bytes, *_MS в ms, остальные — count/pixels. |
| `IMPORT_LEASE_SECONDS` | `60` | 1..3600; claim/heartbeat/fencing, default60s. |
| `IMPORT_RETRY_DELAY_MS` | `1000` | 1..60000ms; max3 retry, multiplier2, cap60000ms. |
| `IMPORT_QUEUE_PREFIX` | `product_import` | 1..60 символов [a-zA-Z0-9_.-], отдельные imports/failed queues. |
| `IMPORT_RATE_LIMIT` | `5` | Число authenticated upload attempts, включая invalid files. |
| `IMPORT_RATE_WINDOW_SECONDS` | `60` | Окно общего PostgreSQL limiter, в секундах;429 и Retry-After. |

Все переменные template используются Compose, APP_ENV явно передаётся app/worker. Специальные test-only TEST_POSTGRES_PASSWORD/TEST_JWT_SECRET находятся в .env.test.example; APP_ENV=test и fixture origin разрешены только изолированными overlays, не production настройкой. Дополнительные host variables E2E_BROWSER_CHANNEL/CI описаны в ci.md, это не backend config.

Default upload10MiB, ZIP expanded100MiB/1000entries/ratio100, dataRows10000/maxPhysicalRow100001/chunk250. PHP upload_max_filesize10M/post_max_size12M/memory256M и nginx client_max_body_size12m — дополнительные compile-time config files: docker/backend/php.ini и docker/nginx/default.conf. Повышение env лимита не повышает эти потолки автоматически. Browser preliminary10MiB тоже фиксирован; server validation окончательная.

Image default5MiB, connect3s/total10s/redirects3/pixels12M/dimension10000/header16384bytes. Форматы JPEG/PNG/GIF/WebP декодируются, результат PNG; address/DNS/redirect policy запрещает private/loopback/link-local. Проверка реального изображения/размеров предшествует записи товара. Изображение/timeout/window/TTL должны быть согласованы с длительностью операции и lease; большие нагрузки не профилировались.

APP_DIAGNOSTICS_ENABLED шаблона изменён с раннего true на false, как runtime default; уже существующий .env не менялся. Публичный Swagger /docs/ — фиксированная политика этого локального deployment, отдельного env-переключателя нет. Там только metadata/spec; защищённые API сохраняют JWT/owner checks.
