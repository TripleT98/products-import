# Messenger/RabbitMQ worker

worker связывает XLSX reader/validator, импорт строки и checkpoint с настоящим RabbitMQ.
HTTP-upload/status/issues endpoints и UI здесь не добавлены. Сообщение содержит только
UUID задачи; путь, данные XLSX и credentials через очередь не передаются.

## Компоненты и ход обработки

ImportQueue собирает Symfony MessageBus с SendMessageMiddleware/HandleMessageMiddleware,
AMQP transports imports/failed и ImportHandler. Producer dispatch только публикует:
без ReceivedStamp сообщение уходит sender-у; в worker ReceivedStamp разрешает handler.
Изменения товара происходят в отдельном процессе после получения сообщения.

ImportMessageSerializer использует versioned JSON `{"jobId":"<uuid>"}` и allowlist
headers: type=import-job.v1, retry-count и failed-from. PHP unserialize и произвольные
stamps/exception traces не читаются и не сохраняются. Ошибочный wire format отвергается.

Handler получает lease хранение задач, открывает generated private upload, сканирует структуру,
сохраняет totalRows, затем последовательно валидирует все строки от заголовка.
Это восстанавливает seen-codes валидация строк при повторе; уже имеющиеся row results пропускаются.
Невалидная строка записывается как failed row и не повторяет сообщение. Валидный DTO
передаётся RowImportService с checkpoint; product/result/issues/counters атомарны.
Cleanup details сохраняются отдельно через noteCleanup. После полного прохода job
становится completed/completed_with_errors. Некорректная книга и недоступный файл
дают безопасный failed с системным issue; частичный прогресс сохраняется.

Lease продлевается при обходе строк и до/между HTTP-загрузками. При временном исключении
текущий token освобождается для retry (expiry в прошлом, только если token всё ещё
владеет processing). Старый token не может освободить или изменить нового владельца.
Для занятого job сообщение откладывается минимум на leaseSeconds+1; это не успешный
пустой import. Terminal/missing job при старом повторном сообщении — no-op.

## Transport и ошибки

- По умолчанию exchanges/queues: product_import.imports и product_import.failed,
  отдельный delay exchange на transport. Они размещаются в RabbitMQ приложения.
- AMQP publisher confirms ожидаются до 5 секунд; connect/read/write timeouts — 2s.
  Connection/transport создаются лениво, секреты DSN не печатаются.
- Messenger retry: максимум 3 повтора после первой попытки, delay 1000 ms, multiplier 2,
  cap 60000 ms, без jitter. Recoverable исключения используют forceRetry=false:
  ограничение не обходится бесконечными retry.
- После исчерпания сообщение помещается в failed transport. Затем handler пытается
  отметить доступный для захвата job как IMPORT_FAILED. Если БД недоступна, ID остаётся
  в failed queue; чужой действующий lease и terminal job не перезаписываются.
- Невалидная строка/ошибка изображения — результат данных, не transport retry.
- Логи worker содержат фиксированные event и job ID: worker_started, retry,
  failed_transport, worker_stopped. Raw DSN, path и stack trace в эти логи не пишутся.

CLI enqueue копирует выбранный локальный файл с лимитом чтение XLSX в UPLOAD_DIR под случайным
32hex.xlsx, mode0600; источник остаётся неизменным. Создаёт durable job и публикует ID.
Если публикация не подтверждена, CLI возвращает nonzero и jobId, пытаясь сохранить
PUBLISH_FAILED. Если и БД недоступна, сохранённый ID остаётся для диагностики: queued
не выдаётся как ложный успех. Outbox/автоматический повтор публикации не реализован.
CLI — доверенный local input; HTTP MIME/extension/ZIP upload validation относится к загрузка XLSX.

## Команды

После обычной установки, миграций и наличия пользователя в основной БД:

```powershell
$docker = 'docker'
& $docker compose stop worker
& $docker compose cp 'path/to/controlled-example.xlsx' app:/tmp/controlled-example.xlsx
& $docker compose exec -T --user www-data app php bin/console imports:enqueue /tmp/controlled-example.xlsx --owner=1
# Ожидается {"jobId":"<uuid>","status":"queued"}; товаров этого job пока нет.
& $docker compose exec -T --user www-data app php bin/console imports:queue-stats
& $docker compose up -d worker
& $docker compose logs --tail=100 worker
```

Основные образы должны быть пересобраны и миграции хранение задач применены до enqueue; это не
выполнялось автоматически для рабочего 8080 в worker. При обычном `docker compose up`
worker активен: профиль worker-pending удалён. Он использует тот же backend image,
uploads/images и environment, ждёт healthy db/rabbitmq, работает через www-data/init,
restart unless-stopped и SIGTERM с grace30s. Entrypoint не запускает миграции сам.

`php bin/worker.php` и `php bin/console imports:work` — один runtime. Поддерживаются
--time-limit=3600 (1..86400) и --limit=0 (unlimited). PCNTL SIGTERM/SIGINT просит
Messenger закончить текущий message и выйти; остановка не происходит посреди строки.
imports:queue-stats показывает ready messages, не включая unacked/delay queues.
Статус и counters пока читаются через storage API/БД, HTTP для них — API импорта.

## Конфигурация

[.env.example](../.env.example)/Compose передают IMPORT_LEASE_SECONDS=60,
IMPORT_RETRY_DELAY_MS=1000, IMPORT_QUEUE_PREFIX=product_import. Prefix допускает только
ASCII letters/digits/underscore/dot/hyphen; числовые значения проверяются. Брокер использует
существующие RABBITMQ_HOST/PORT/DEFAULT_USER/DEFAULT_PASS/DEFAULT_VHOST.
Default lease должен быть больше одной блокирующей операции; default network limit загрузка изображений
10s. Начальный scan книги/длительные SQL также должны укладываться в выбранный TTL.

Только imports:work --test-image-origin разрешает exact fixture origin при APP_ENV=test
и точных isolated DB реквизитах. Производственная политика адресов не отключается.
В worker-check image server слушает loopback в namespace fixtures, разделяемом worker;
внешние тестовые URL не нужны. Production CLI guard проверен отрицательно.

## Воспроизводимая проверка без рабочих данных

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-worker.py
```

Ожидается exit0/PASS у всех трёх команд; PHPUnit — 348 tests, включая 10 новых wire/
storage/CLI guard scenarios. Make equivalents: make check, make test, make worker-check.
Последняя команда обязательна для проверки брокера: общий PHPUnit-контур его не запускает.
Нужны Docker Desktop Linux Engine и Python3; PHP/Composer/Make на хосте не нужны.

check-worker.py собирает настоящий production image worker и отдельный testing image
fixture-tools, создаёт project product-import-worker-check с PostgreSQL/RabbitMQ tmpfs,
internal network и двумя собственными disposable uploads/images volumes. Ports не
публикуются. Проверенная topology не подключает .env/credentials/volumes приложения.
При завершении удаляются только resources этого конкретного test-проекта, включая его
сгенерированные файлы; рабочие named volumes не затрагиваются.

Проверяются:

1. Worker остановлен: CLI enqueue → queued job, 1 ready message в настоящем RabbitMQ,
   0 товаров и row results.
2. Запущен отдельный production worker container: XLSX с valid/invalid/valid даёт
   total=processed=3, created=2, failedRows=1, warningCount=1, completed_with_errors;
   issues rows3/4, ведущие нули, discount25.00 и два настоящих PNG подтверждены.
3. Повтор terminal message не меняет counters; новая задача обновляет существующие
   товары и не оставляет заменённые файлы.
4. Некорректный ZIP/XLSX даёт безопасный fatal WORKBOOK_INVALID без строк.
5. Контролируемый временный SQL-сбой вызывает настоящий delayed AMQP retry и один
   согласованный итог, без удвоения.
6. Постоянный SQL-сбой даёт ровно 3 retry, failed job и retained failed message.
7. SIGTERM останавливает worker с exit0. Остановленный test broker даёт nonzero enqueue
   и durable PUBLISH_FAILED, без ложного queued success.

Источники synthetic XLSX/PNG генерируются тестом. Оригинал пользователя и внешние
картинки не читаются/не публикуются. Logs/report: tmp/t15-worker.log и
локальный отчёт проверки.

## Границы

Подключение реального worker выполнено, но восстановление kill/restart, transport redelivery при
обрыве процесса и автоматическая сверка orphan files ещё не проверены. Нет обещания
exactly-once. SIGTERM после долгого message может превысить grace30s и перейти в kill;
checkpoint/lease — основа последующего восстановления, а не выполненная восстановление.
Failed queue сохраняет сообщение для разбора; автоматический replay/admin UI здесь
не добавлены. Upload retention, durable cleanup sweeper и общая транзакция filesystem/DB
не реализованы. Предельный XLSX/длительные network нагрузки не профилировались.
загрузка XLSX не начата; рабочий 8080 не обновлялся автоматически.

Composition/runtime сверены с [официальной документацией Messenger](https://symfony.com/doc/current/messenger.html)
и установленными API Messenger/AMQP 8.1.10.


В загрузка XLSX publisher дополнен enqueueSaved для уже проверенной HTTP private copy без второго
копирования. enqueueLocal сохраняет trusted CLI поведение; HTTP validation описана
отдельно в [import-upload.md](import-upload.md). Тестовый fixture user теперь имеет
настоящий password hash для проверки HTTP login; production credentials не менялись.

В восстановление `python scripts/check-recovery.py` подтвердил SIGKILL/redelivery/lease recovery,
два consumer, transient mid-row rollback/retry и exhausted failed transport. Системные
ошибки БД больше не становятся permanent row failure. [Воспроизведение и границы](import-recovery.md).
