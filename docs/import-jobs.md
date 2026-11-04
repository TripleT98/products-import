# Хранение задач и контрольных точек

хранение задач хранит задачи, issues и результаты строк. Messenger, публикация сообщений, worker,
upload и новые HTTP-маршруты не добавлены. Публичные проекции сохраняют [OpenAPI](openapi.json).

## Схема

Миграция `Version20261102000100` создаёт три таблицы:

| Таблица | Назначение |
|---|---|
| import_jobs | UUID, owner FK users/RESTRICT, generated private upload name, status, nullable total, counters, physical checkpoint, lease token/generation/expiry, timestamps, безопасная fatal reason |
| import_row_results | PK (job_id,row_number), created/updated/failed, исторический product ID, attempts, obsolete paths и pending cleanup paths |
| import_issues | identity ID, job FK/CASCADE, severity/code/message, nullable row/externalCode/field, уникальный fingerprint |

Результаты/issues удаляются каскадно с job. Product ID квитанции — историческое число
без FK: удаление товара не должно уничтожать учёт. Успех требует положительный ID,
failed — null. DB checks защищают states, counters и их сумму, total>=processed,
terminal timestamps и row numbers. Индексы: owner, (job,id) issues, unique (job,issue_key).

ImportJob/ImportRowResult/ImportIssue — явно readOnly ORM models с readonly полями.
Атомарные мутации выполняют Repository через DBAL, избегая устаревших ORM-снимков
при SQL-инкрементах. Все семь моделей проверяются schema validation; таблицы не скрыты
asset filter. Сущности напрямую не сериализуются в API. Прежняя миграция схема БД сохранена.

## Сервис и ownership

ImportJobService владеет свежей EM/connection на операцию. Для отдельной БД принимается
`Closure(): EntityManagerInterface`; фабрика должна выдавать открытую свежую сессию без
внешней транзакции. Writer, cleaner и job service должны использовать одну БД.

`create(ownerId,fileName)` создаёт queued, totalRows=null, нулевые counters, checkpoint=1
(заголовок), UUID v4. Owner должен существовать; fileName — `[a-f0-9]{32}.xlsx`, не
произвольный серверный path. Сохранение/проверка самого upload относится к загрузка XLSX.

`findForOwner` и `issuesForOwner` возвращают null и для missing, и для foreign job.
ImportJobView содержит только прежние поля OpenAPI, UTC RFC3339 timestamps и безопасную
fatal reason. Owner/file name/token/checkpoint в JSON не входят. Issues используют
SQL COUNT/LIMIT/OFFSET и id ASC, page>=1, limit 1–100/default 50. Внутренние lease/rowResult
методы предназначены доверенному обработчику, не HTTP.

Состояния: queued → processing → completed/completed_with_errors/failed.
`complete` требует известный total и processed=total; failedRows>0 или warnings>0
выбирают completed_with_errors. total=0 допустим; установленный total не меняется.
Terminal jobs не захватываются и не мутируются. `fail` сохраняет частичный progress
и системный issue с nullable row/field. ImportFatalCode задаёт фиксированные безопасные
code/message, без raw exception/path. `failQueuedPublication` допускает queued→failed
для будущей ошибки публикации; публикации/outbox здесь нет.

## Claim, heartbeat и восстановление

`claim(id)` использует `FOR UPDATE SKIP LOCKED`: для queued или processing с истёкшим
lease атомарно устанавливает новый случайный token/generation и возвращает ImportLease.
Для занятого/terminal/ещё действующего job возвращает null, не ожидая чужой lock.
Lease по умолчанию 60 секунд, constructor leaseSeconds допускает 1–3600.

Источник времени — PostgreSQL clock_timestamp. Heartbeat продлевает только ещё
действующий lease. Прогресс/завершение проверяют token, processing и expiry под row lock.
После перехвата старый token не может менять counters/issues или товар через checkpoint.
При смерти процесса транзакционные locks освобождаются с соединением, оставшийся
processing можно перехватить после expiry. Сервис не убивает старый процесс и не
запускает планировщик повторов. Реальный Messenger kill/restart — будущая восстановление.

RowImportService с checkpoint вызывает heartbeat перед каждым URL и после подготовки,
вне товарной транзакции. TTL должен превышать длительность одной блокирующей операции:
default 60s больше default сетевого лимита загрузка изображений 10s. Будущий caller также должен
обновлять lease на других длительных стадиях и прекращать запись при утрате token.

## Общий commit товара и результата строки

ProductWriter и RowImportService совместимо принимают optional ProductWriteCheckpoint.
Без него режим импорт строки сохранён. JobRowCheckpoint в writer-транзакции:

1. Блокирует job, проверяет lease и соответствие physical row DTO.
2. Возвращает прежнюю успешную квитанцию при replay, без новой записи товара.
3. После реального SQL flush товара/атрибутов/изображений фиксирует result/issues/
   counters/last_row_number до общего commit.

Ошибка товара не оставляет success. Ошибка checkpoint откатывает также уже выполненный
SQL товара; импорт строки очищает новые неиспользуемые файлы. Path locks и shared files сохранены.
Уникальная (job,row) и job lock исключают двойной учёт. Первый результат неизменен;
replay не перезаписывает товар, даже если его затем изменила другая задача.

Failed записывается явно `recordFailure` с хотя бы одним error issue; повтор возвращает
false без новых counters/issues. Failed строку при resume пропускают по rowResult.
Новые physical row numbers строго возрастают с возможными пропусками пустых строк.
processedRows — число результатов, не номер строки. Несколько ошибок одной строки
увеличивают failedRows на 1, warnings — только warningCount. Всегда
processed=created+updated+failed. Blank rows не записываются.

Для resume исходный сохранённый файл должен быть неизменным. Валидацию воспроизводят
от заголовка, чтобы сохранить seen-codes валидация строк; запись завершённых строк пропускают по
ledger. Одного перемещения reader на checkpoint без восстановления validation state
недостаточно. Это контракт для будущего worker, не реализация worker.

## Пример storage API

```php
$jobs = new App\Import\Job\ImportJobService();
$jobId = $jobs->create($authenticatedOwnerId, $savedPrivateUploadName);
$lease = $jobs->claim($jobId);
// Если lease=null, обработку не начинать.
$jobs->setTotal($lease, $nonEmptyRows);
$result = $rows->import($dto, $jobs->checkpoint($lease, $dto->rowNumber));
if ($result->write === null) {
    $jobs->recordFailure($lease, $dto->rowNumber,
        array_map(App\Import\Job\ImportIssueData::fromRowIssue(...), $result->issues));
}
```

Это примеры методов хранения, не готовый handler. Validation warnings можно передать
через checkpoint(...,issues), downloader warnings добавляются через withIssues.
При recordFailure также учитывают validation issues вызывающего кода. Утрата lease
не является row error: старый caller прекращает работу, проверка token не обходится.

После commit `noteCleanup(lease,rowNumber,warnings,pendingPaths)` сохраняет cleanup
warnings и список безопасных public paths. Fingerprint предотвращает повторный
warningCount; pending paths заменяются текущим набором. Их очистка не стирает историю
warnings. Нужен действующий lease, terminal maintenance пока нет. Дополнительные
warnings не меняют processed/created/updated/failed.

Success и основные issues атомарны с товаром. Filesystem cleanup и его details —
последующая операция; авария между этапами требует будущей сверки. Нет обещания
общего PostgreSQL/filesystem commit, exactly-once брокера или фонового уборщика.

## Проверка

PowerShell при работающем Docker Desktop Linux Engine:

```powershell
python scripts/verify.py check
python scripts/verify.py test
```

Ожидаются exit 0, PASS и PHPUnit `OK (348 tests, ... assertions)`; счётчик assertions
немного зависит от ожиданий запись товаров. Добавлены 12 PostgreSQL tests в ImportJobTest и два
HTTP/files tests в RowImportServiceTest: ownership/DTO/durability, transitions/totals,
counters/replay, issues dedup/pagination, оба направления rollback, stale-token
fencing, реальный concurrent claim, resume ledger, cleanup details, HTTP heartbeat
и очистка реальных файлов при checkpoint failure.

Expiry в тестах управляется SQL вместо минутных ожиданий. Конкуренция проверяется
двумя независимыми PHP-процессами: пока первый держит row lock, второй claim уже
завершается с null. После освобождения первый получает единственный действующий token.
Оба CLI процесса затем завершены; истёкший lease перехватывается новым владельцем.

Runner также проверяет миграции/schema, fixtures дважды, frontend; check — PHPStan 5,
форматирование, lint/build. MigrationLifecycleTest выполняет up/down/up и schema sync
семи моделей в отдельной БД. Private databases, процессы/HTTP fixtures и test resources
удаляются; рабочие credentials/volumes, исходный XLSX и внешние картинки не используются.


В worker добавлены внутренние fileForLease/dispatchStatus и releaseForRetry (только
matching token), настоящий worker и явная post-commit запись cleanup details.
[Команды и проверка брокера](import-worker.md). Основная хранение задач-схема/API не менялись.
