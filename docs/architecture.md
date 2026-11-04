# Архитектура и фактический контракт

Архитектура backend, frontend, очереди и хранения. HTTP-контракт описан в [OpenAPI](openapi.json), правила книги — в [формате импорта](import-format.md).

## Компоненты и границы

| Слой | Реальные компоненты / ответственность |
|---|---|
| nginx | Angular SPA/deep links, public /docs/ и /images/, /api/→FPM; uploads и .staging не публикуются |
| Slim ApplicationFactory / middleware | Routing, RequestIdMiddleware, AuthMiddleware, ApiErrorHandler; контроллеры только проверяют transport/делегируют/возвращают DTO |
| AuthController/AuthService/UserRepository/TokenService | Password bcrypt, HS256 JWT подпись/issuer/audience/expiry, stateless owner; публичный login, без регистрации/refresh |
| ProductController/ProductQueryService/ProductRepository | COUNT/LIMIT/OFFSET, id ASC, name/range filters, list/detail DTO, связанные attrs/images без N+1 |
| ImportController/ImportUploadService/ImportRateLimiter | Authenticated upload, limiter в PostgreSQL, private file/preflight/job/publisher; нет импорта строк в POST |
| WorkbookReader/HeaderMapper/XlsxPackage/WorkbookLimits | Один непустой лист, headers mapping, chunk reader, bounded ZIP/rows, формулы/внешние связи не исполняются |
| RowValidator/RowValidationService/DecimalParser/DecimalCalculator | Чистый RowDto либо RowIssue; exact decimal/HALF_UP, attributes, ImageCandidateExtractor, первая валидная строка кода |
| ImageDownloader/ImageUrlPolicy/ImageStorage/ImageFileCleaner | DNS/IP/redirect/content/limits до транзакции товара; подготовленный PNG либо typed warning; public path и reference-safe cleanup |
| RowImportService/ProductWriter | Оркестрация строки/upsert и полная замена attrs/актуализация images; атомарный row checkpoint, rollback/cleanup |
| ImportJobService/repositories / JobRowCheckpoint | Durable jobs/issues/row ledger, ownership/counters, lease/heartbeat/fencing |
| ImportPublisher/ImportQueue/ImportHandler | Persistent AMQP ID, Messenger worker, bounded retry/failed transport, повторная доставка/claim/result skip |
| Angular core/models/features/store | Typed API adapters, AuthSession/guard/interceptor, lazy routes, NgRx Store/Effects списка, Material UI/async pipe, общий report widget |

Схема: products(external_code unique, DECIMAL18,2), product_attributes(product/key unique), product_images(product/url unique), users, import_jobs/import_issues/import_row_results и limiter;3 миграции. [Точные таблицы/FK/DECIMAL](schema.md), [jobs](import-jobs.md). JSON camelCase, SQL snake_case; query исключения price_min/price_max. ORM Entity не сериализуются напрямую. HTTP CRUD записи не задан, Repository/service CRUD проверен тестами.

## Цепочка импорта

JWT → shared limiter → actual file/MIME/ZIP/rows preflight → private generated file +queued job → publisher confirm →202/jobId. Worker читает private XLSX из shared volume через отдельный AMQP transport; POST не пишет товары. Symfony Messenger8.1/RabbitMQ, сообщения только с job UUID. Нельзя передавать File/Entity/JWT в сообщение.

Worker claim создаёт fencing token, читает/валидирует строки, скачивает изображения вне DB transaction. Одна транзакция объединяет товар/relations, row ledger/issues/counters. Невалидная строка фиксируется как failed row, картинка как warning, следующие строки продолжаются. Прежние committed rows не откатываются. Общая транзакция на всю книгу не используется. Один external_code между jobs обновляется последней successful transaction; concurrency/unique conflicts имеют bounded retry с новым EntityManager. Row ledger не даёт повторной доставке дважды зачесть результат.

Набор атрибутов заменяется полностью; URL, отсутствующий в новом DTO, удаляется. Старая успешная копия того же URL сохраняется при новом download warning. Новые корректные PNG публикуются атомарным rename, rollback чистит prepared files; после commit reference-safe cleanup удаляет устаревшие. Произвольный SIGKILL может оставить физический orphan/staging file: sweeper нет. DB и файловая система не имеют единой ACID-транзакции.

## Статусы, API и ошибки

queued → processing → completed/completed_with_errors/failed. totalRows=null до scan; после обычного завершения processedRows=createdProducts+updatedProducts+failedRows; warnings отдельно. Fatal book →WORKBOOK_INVALID, системный отказ/исчерпание retries →IMPORT_FAILED при доступной БД. Counters/issues сохраняются и читаются после refresh/restart. [Status/issues/limiter/health](import-api.md).

OpenAPI3.0.3 содержит7 основных операций и existing optional /api/_diagnostics (8 всего). Health/login публичны; products/imports owner endpoints защищены Bearer JWT. Foreign/missing job→404. Product list доступен всем authenticated users, постоянного product→job отношения нет. Validation400, auth401, missing404, media415, upload413/422, limiter429/Retry-After, safe500/503. Details всегда массив, nullable metadata присутствует с null. Optional diagnostics503 возвращает diagnostic report; остальные API ошибки — error envelope.

DECIMAL значения — канонические строки с двумя знаками; timestamps UTC RFC3339; ProductDetail attributes/key ASC и images/position,id ASC. Pagination page>=1/limit1..100/total/totalPages, empty totalPages0; product default20, issue default50. API adapters не преобразуют деньги в float. Invalid/duplicate query values проверяются сервером. [Формальный контракт и примеры](openapi.json).

Swagger UI5.34.1 поставляется локально в production nginx; /docs/ и spec public, запросы API остаются same-origin с собственным JWT/ownership. Нет online validator/CDN, persistAuthorization=false, queryConfigEnabled=false; refresh очищает auth. Это документация, не administrative bypass. [Политика/проверка](api-documentation.md).

## Очередь и реальные границы восстановления

Persistent broker message ack после обработки; SIGKILL/закрытие соединения допускает redelivery. Живой lease не перехватывается; delay занятого lease минимум TTL+1, новый claim после expiry меняет fencing token. Повтор перечитывает validation, пропускает committed ledger rows, terminal/missing delivery — no-op. Max3 retries после первой попытки, exponential multiplier2/cap60s, затем failed transport. Recovery реально проверен SIGKILL/two consumers/mid-row transient failures, но это не exactly-once delivery. [Доказательства и ограничения](import-recovery.md).

Outbox/периодического reconciler нет. При неопределённом publish попытка отменить ещё queued job даёт QUEUE_PUBLISH_FAILED/503; race с worker/неопределённый confirm не гарантирует отсутствия фоновой работы. Нельзя обещать восстановление любого queued без broker message. Длительный полный отказ БД может не позволить записать terminal status. Failed transport/replay требуют оператора; recovery/replay/admin CLI/API не добавлены. imports:queue-stats показывает ready counts, не unacked/delayed. Не удалять broker volume ради retries.

## Frontend

AuthSession хранит token в sessionStorage вкладки и ограничивает local expiry; server проверяет подпись/пользователя. Auth interceptor только для same-origin /api, late401 не стирает более новую session. Safe return URL, lazy route guard, shared safe notifications. [Auth](frontend-auth.md).

Root provideProductsStore регистрирует products reducer/Effects, immutable/serializable state. loadProducts →switchMap ProductService.list→success/failure, async pipe/store.select; server page без local slice. Route query управляет filters/debounce/page; back links сохраняют допустимые query keys. [Store](products-store.md), [список](products-list.md).

ProductDetailPage switchMap загружает DTO, показывает local images/path, source URL только текст. ImportPage блокирует duplicate upload и auto-resend не делает. Общий ImportReportWidget на job route/карточке с importJobId использует response-relative2s poll без overlap; error/terminal/destroy останавливают HTTP/timer. ImportIssuesReport запрашивает server page50/default, обновляется при status/failedRows/warnings revision, своего timer нет. Widget относится ко всей выбранной задаче; no-ID/недоступный job не скрывает товар. [Upload](import-ui.md), [report](import-report.md), [карточка](product-detail.md).

## Конфигурация, тесты и deployment

[Все env/default/единицы](environment.md), [Windows запуск без Make](../README.md). App/worker получают одинаковые настройки и uploads/images volumes; nginx только read-only images. PostgreSQL18 volume монтируется в /var/lib/postgresql; .env не попадает в images/Git. APP_ENV=production по умолчанию, fixtures включаются только command-scoped development/test. Диагностика optional/defaultfalse, health всегда публичен.
