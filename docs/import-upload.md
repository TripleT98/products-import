# HTTP-загрузка XLSX

Добавлен защищённый `POST /api/imports` по существующему [OpenAPI](openapi.json).
Успех: HTTP 202 `{"jobId":"<uuid>","status":"queued"}` после сохранения private copy,
durable job и подтверждённой публикации в RabbitMQ. В запросе товары не записываются.
JWT авторизация обязателен; owner берётся только из verified authUser. Файл/owner из query или
имя клиента не определяют серверные пути/владельца. Дополнительные multipart fields,
включая ownerId, отклоняются. GET status/issues, limiter и окончательный health — API импорта.

## Компоненты и границы валидации

ImportController тонкий: verified identity → ImportUploadService → JSON 202.
Service проверяет HTTP/file boundary, использует UploadStorage и WorkbookReader,
затем лениво вызывает ImportPublisher::enqueueSaved. ApplicationFactory регистрирует
только новый POST; согласованные API/схема/маршруты чтения не изменены.

Проверки до создания job/публикации:

- multipart/form-data, ровно один UploadedFileInterface в поле file, без extra fields;
- PHP upload status: missing/partial — 400, byte limits — 413, server storage failure — 503;
- расширение .xlsx (регистр не важен); исходное имя никогда не участвует в сохранении;
- reported size и фактические байты при копировании (max+1 detection), пустой файл;
- Fileinfo MIME private copy: XLSX/ZIP/octet-stream; клиентский MIME не считается
  доказательством типа. Валидная книга с неверным MIME клиента принимается; HTML/text
  с заявленным XLSX MIME отвергается;
- ZIP consistency, обязательные OOXML parts, encryption/macros/external entities,
  archive entry/count/ratio/uncompressed bounds через существующий XlsxPackage чтение XLSX;
- новый validateUpload использует тот же безопасный chunk reader для физических row
  numbers и суммарного числа непустых data rows во всех листах, без вычисления формул.

Business headers, выбор единственного непустого листа, row DTO validation остаются
worker-ом, как определено OpenAPI. Поэтому корректный OOXML с неправильными business
headers может получить 202, затем job станет failed. Это не HTTP-импорт и не отмена
resource checks. Строки и картинки в HTTP не импортируются, URL не открываются.

## Ошибки

| HTTP | Code | Когда |
|---|---|---|
| 400 | INVALID_REQUEST | Нет single file, nested/wrong field, extra fields, incomplete upload |
| 401 | UNAUTHORIZED | Нет действующего JWT, до сохранения private copy/job |
| 413 | UPLOAD_TOO_LARGE | Лимит тела nginx/PHP либо actual/reported file bytes |
| 415 | UNSUPPORTED_FILE_TYPE | Не multipart, расширение или фактический MIME не поддержаны |
| 422 | INVALID_WORKBOOK | Пустая/повреждённая/зашифрованная ZIP-книга либо ZIP/row bounds |
| 503 | SERVICE_UNAVAILABLE | Ошибка private storage/БД/публикации |

Ответы приложения имеют JSON error envelope/details=[], X-Request-ID и no-store;
nginx 413 сохраняет существующий JSON code/X-Request-ID. PHP post_max_size discard
распознаётся по пустым files + Content-Length, а не маскируется под missing file.
Raw client names, server paths, tokens, DSN и exception text не попадают в ответ.

## Файлы, публикация и очистка

copyStream создаёт exclusive file под случайным 32hex.xlsx в каноническом UPLOAD_DIR,
сразу ставит 0600 и копирует с ограничением фактических байтов. Успешная private copy
не доступна через nginx; /uploads/ явно отвечает 404. Корень лежит вне web root.
App и worker используют тот же volume/путь/UID www-data (33).

Rejected validation удаляет свою копию, не создавая job/message. EnqueueSaved не
копирует файл повторно; enqueueLocal worker переиспользует тот же publisher и сохраняет
прежний trusted CLI режим. При ошибке создания job копия очищается. При EnqueueFailed
файл уже принадлежит durable job: он сохраняется, job переводится в PUBLISH_FAILED,
HTTP отвечает 503, а не ложным 202. Если БД в этот момент также недоступна, сохранённый
job ID/состояние требуют разбора после восстановления. Outbox/автопубликации нет.

Принятые файлы сохраняются для worker/recovery; retention и аварийный scavenger пока
не реализованы. Нет общей транзакции filesystem/DB/брокера при произвольном kill.

## Лимиты

Используются прежние XLSX_* [.env.example](../.env.example), по умолчанию file10MiB,
uncompressed100MiB, entries1000, compression ratio100, data rows10000,
physical row100001, chunks250. HTTP file limit фактически также ограничен текущими
PHP upload_max_filesize10M/post_max_size12M и nginx body12m. Увеличение XLSX_MAX_FILE_BYTES
само не увеличивает PHP/nginx caps. Resource/row checks выполняются до enqueue;
при их превышении файл не сохраняется как принятый job.

Начальный scan синхронный и ограничен этими budgets. Предельная нагрузка и длительность
на максимальных книгах не профилировались; fastcgi timeout и worker lease требуют
учёта при отдельном изменении limits. Rate limiter в загрузка XLSX не добавлен — это API импорта.

## Проверка в PowerShell

При работающем Docker Desktop Linux Engine:

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-upload.py
```

Ожидается exit0/PASS у всех команд; PHPUnit — 348 tests. Общий набор содержит 13 новых
integration cases Upload boundary. Для отдельной регрессии CLI worker:
`python scripts/check-worker.py`. Make equivalents: check, test, upload-check, worker-check.
Хостовые PHP/Composer/Make не нужны. Сеть нужна при отсутствии Docker/package cache.

check-upload.py использует compose.worker-test.yaml + compose.upload-test.yaml в
строго отдельном project product-import-upload-check: настоящие nginx/FPM/Slim,
RabbitMQ, PostgreSQL, production app/worker images и собственные uploads/images volumes.
Ports не публикуются; curl-клиент работает внутри isolated network namespace.
Fixture server на loopback доступен worker только с явной test policy, production
image policy не ослаблена. Базовый worker CLI runner сохранён и отдельно прошёл регрессию.

Проверено через настоящий multipart/cURL:

1. Missing/partial form shapes, auth, wrong field/file[], forged owner, extension,
   spoofed MIME, corrupt ZIP, valid-container ZIP bomb/extra entries/encryption/rows,
   app byte cap, PHP upload cap и nginx body cap. Отказ не добавляет job/private files.
2. Валидная книга с client MIME text/plain и недоверенным именем получает 202;
   private copy random/0600/UID33, source SHA256 неизменён, worker может читать ту же
   копию, URL /uploads/<name> возвращает 404.
3. Worker остановлен: job queued, 1 ready RabbitMQ message, 0 товаров.
4. Запуск отдельного worker: total=processed3, created2, failedRows1, warningCount1,
   completed_with_errors; два PNG реально доступны через nginx.
5. Broker остановлен: HTTP503, durable PUBLISH_FAILED job/private file сохранены,
   новых товаров нет, ложного 202 нет.

Все private test DB/files/processes/containers/networks/volumes удаляются runner-ом;
он проверяет topology/project/volume scope до cleanup. Рабочие volumes/.env/БД не
подключаются. Исходный XLSX пользователя и внешние картинки не используются.
локальный отчёт проверки.

## Ручной multipart на обновлённом основном проекте

После сборки актуальных app/worker/nginx, миграций и настройки JWT по README/auth:

```powershell
curl.exe -X POST 'http://127.0.0.1:8080/api/imports' -H "Authorization: Bearer $token" -F "file=@$xlsxPath"
```
