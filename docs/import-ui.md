# Загрузка и прогресс в Angular


## Поведение

`/import` принимает один .xlsx, показывает имя/размер и проверяет расширение (без учёта регистра), непустой файл и клиентский предел10 МиБ. MIME/ZIP/структуру/дополнительные лимиты по-прежнему проверяет сервер; browser MIME не считается доказательством. Клиентский предел соответствует default backend, сервер может установить более строгий и вернуть413.

Отправка — прежний ImportService.upload/FormData file без ручного Content-Type/boundary. До завершения запроса input/button заблокированы, повторный click игнорируется. После202 блокировка сохраняется до перехода /import/{jobId}, чтобы короткое окно navigation не создавало второй POST. Ошибка снова разрешает ручной retry; нет автоматической повторной отправки файла.400/401/413/415/422/429/503/network получают безопасные сообщения;429 показывает корректный Retry-After. Raw body не выводится. Навигация со страницы отменяет frontend request, но не гарантирует отмену уже принятого сервером job.

`/import/:jobId` восстанавливается по URL без исходного File и local state. Первый GET немедленный; следующий — через2s ПОСЛЕ ответа предыдущего. pollImport/defer/expand никогда не перекрывает запросы и не накапливает timer ticks. Состояния loading/ready/error; counters total/processed/created/updated/failedRows/warningCount. totalRows=null показывает «Определяется» и indeterminate bar; известное total — determinate. Терминальные completed/completed_with_errors/failed останавливают polling, fatalError показывается безопасным текстом. Fatal с неизвестным total не оставляет движущийся indeterminate bar.

Ошибка HTTP/network останавливает polling до «Повторить получение статуса», без циклического запроса ошибки.404/чужая задача — единое сообщение недоступности; malformed UUID не отправляется в API. Прежний interceptor обрабатывает401. «Остановить обновление» отменяет timer/in-flight GET, но не отменяет сам импорт; «Продолжить» начинает новый GET. При уходе со страницы async pipe/refCount отписывается и убирает запрос/timer. При смене jobId switchMap отменяет старую задачу наблюдения. Пауза локальна: refresh снова получает актуальный статус и продолжает только если он активен.

## Повторить проверки

PowerShell, Docker Desktop Linux Engine/Python:

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-import-ui.py
```

Ожидаются exit0/PASS. Check: frontend lint/strict app+spec typecheck/104 tests/build и backend365 tests/2168 assertions/static/style/schema/fixtures. Test повторяет suites. Добавлены17 tests upload/poll lifecycle: invalid ext/empty/bytes/unknown client MIME, duplicate pending/accepted click, status errors/Retry-After/manual retry, destroy cancellation, slow request/no overlap, response-relative delay, unknown total/counters, all3 terminal states, network error/retry, malformed/404/unsubscribe. Shell test завершает начальный job lookup terminal fixture, чтобы не оставлять polling.

`check-import-ui.py` / `make import-ui-check` требует host Node/npm/установленный Microsoft Edge и использует ignored Playwright1.56.1 tooling вход. Создаёт отдельный product-import-import-ui-check: production nginx/FPM/worker, настоящий RabbitMQ/PG/fixture HTTP; свои network/volumes, только nginx на случайном loopback port. Main .env/данные не используются. Worker сначала остановлен; browser test запускает только worker этого проверенного test-проекта аргументами Docker CLI без shell. Cleanup всего собственного проекта в finally.

Synthetic import-ui-fixture.php создаёт книгу12 строк:11 valid/1 invalid price, image404 warning и управляемые slow HTTP images. XLSX переносится только в ignored tooling для browser file input; оригинал пользователя не читается/не изменяется/не публикуется. Второй XLSX структурно валиден для HTTP preflight, но с неподдерживаемым business header — реальный worker fatal WORKBOOK_INVALID. Test overlay увеличивает только maxDataRows загрузка XLSX test config до20, limiter5; production limits/API не меняются.

Browser проверяет:

1. Login→file selection/name/size, invalid.csv без POST, controlled429/Retry-After и разрешённый retry. Два быстрых click при delayed real POST создают ровно одну настоящую загрузку.
2. Worker stopped: queued/unknown total, повторные GET, pause/resume, refresh восстановления и прекращение polling после ухода. Controlled503 останавливает запросы до manual retry.
3. Worker started: processing/known12 и реальные counters, completed_with_errors с processed12/created11/updated0/failed1/warning1.
4. После terminal и после refresh terminal — никаких следующих GET в течение окна больше интервала polling. Следующий файл даёт real failed/WORKBOOK_INVALID, который также не продолжает polling.
5. Desktop/mobile390px screenshots и отсутствие horizontal overflow.

Успешные POST/GET/jobs/worker/products настоящие. Только429/503 и краткая задержка POST управляются browser interception для error/pending UI; локальная сеть не отключается. Unit network case — штатный HttpTestingController error. Отчёт tmp/verification-import-ui.json; screenshots tmp/t20-browser-tooling/import-progress.png, import-finished.png, import-mobile.png визуально проверены. локальный отчёт проверки, статус.

## Проверить вручную

В актуальном demo с подготовленными JWT/миграциями/fixtures и shared volumes войдите и откройте /import. Выберите тестовый XLSX, проверьте имя/размер, нажмите «Начать импорт»: появится /import/{id}. При остановленном worker видно «В очереди», после запуска — counters и завершение. Refresh страницы задачи восстанавливает данные. В DevTools Network одновременно не должно быть двух GET status; после паузы/ухода/terminal GET прекращаются. Пауза обновления не является отменой server job.

## Ограничения

Нет отчёт импорта issues/report/widget, upload byte-percent (POST имеет indeterminate pending), server cancellation API или recovery потерянного202 response. Если сеть оборвалась после принятия файла, job может продолжаться без полученного клиентом ID; автоматический resend намеренно не добавлен. Серверный размер/ZIP limits окончательны; frontend не парсит книгу. Unknown total не превращается в ложные проценты. Пауза/selected File не переживают refresh, job status восстанавливается из БД по URL.

Большие XLSX/нагрузка/фоновые browser timer throttling не профилировались. Browser runner фактически проверен на Windows/Edge, нужен Node/npm и dynamic port. Прежние npm audit issues/worker outbox/retention/orphan ограничения не исправлялись; dependencies/locks/API неизменны. Следующие задачи не начинались.
