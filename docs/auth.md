# Backend-аутентификация авторизация

Angular login/sessionStorage/guard/interceptor реализованы и проверены в вход: [frontend-auth.md](frontend-auth.md). Исторические записи авторизация ниже описывают состояние своего этапа.

Основа — R10/авторизация и согласованный [OpenAPI](openapi.json). fixtures готова: users/fixtures, PostgreSQL и Repository проверены. Реализован POST /api/auth/login; товарные GET list/detail добавлены в [API товаров](products-api.md); импорт/health и Angular login появятся в следующих задачах.

## Контракт

POST /api/auth/login с Content-Type application/json (допустим charset) принимает только объект с непустыми строковыми username/password. Лишние/отсутствующие поля, неверные типы и повреждённый JSON → 400 INVALID_REQUEST; другой Content-Type → 415 UNSUPPORTED_MEDIA_TYPE. Успех 200:

```json
{"accessToken":"<JWT>","tokenType":"Bearer","expiresIn":3600,"user":{"id":1,"username":"fixture-user"}}
```

ID определяется БД. Хеш/пароль не возвращаются. Неверный пароль и неизвестный пользователь → одинаковые 401 с WWW-Authenticate: Bearer:

```json
{"error":{"code":"UNAUTHORIZED","message":"Требуется вход.","details":[]}}
```

Тот же ответ у middleware для отсутствующего/повреждённого/поддельного/истёкшего токена или удалённого пользователя. Успешный login и ответы ошибок имеют Cache-Control: no-store. Параметры запроса/cookies не заменяют Authorization: Bearer <JWT>.

Контроллер проверяет HTTP/DTO и вызывает AuthService. AuthService читает UserRepository, проверяет password hash и вызывает TokenService. PasswordHasher использует штатные password_hash/password_verify с PASSWORD_DEFAULT (bcrypt в выбранной PHP); случайная соль, отказ от пустого/NUL/длиннее 72 байт при создании хеша и от усечения bcrypt при проверке. Регистрация/смена пароля/refresh endpoints не добавляются. Для неизвестного username также выполняется password_verify с dummy hash. Автоматический rehash при входе не меняет детерминированные fixtures.

Тестовый вход fixtures — fixture-user / password, только для отдельного demo/test окружения. Раннее demo/example-only в OpenAPI — иллюстрация формы, не действительная учётная запись. Текущие fixtures fixtures содержат публичные фиксированные данные, а не создают рабочих пользователей через env; прежняя целевая запись об env credentials уточнена в архитектуре. Новые реальные пользователи и их provisioning этим шагом не добавляются.

## JWT и конфигурация

В .env.example описаны переменные; Compose передаёт их app/worker через общее environment. Реальная .env не менялась.

| Переменная | Значение/ограничение |
|---|---|
| JWT_SECRET | Обязательный секрет HS256 не короче 32 байт, без рабочего значения в Git |
| JWT_ISSUER | product-import по умолчанию |
| JWT_AUDIENCE | product-import-web по умолчанию |
| JWT_TTL_SECONDS | 3600 по умолчанию, целое 1…86400 |

Для нового локального секрета можно самостоятельно выполнить `python -c "import secrets; print(secrets.token_urlsafe(48))"` и сохранить результат только в игнорируемой .env. Не использовать публичный TEST_JWT_SECRET из тестового контура. Отсутствующий/неправильный signing config не позволяет выдать/принять JWT: безопасный 500 без раскрытия значения. БД недоступна → безопасный 503 SERVICE_UNAVAILABLE.

Подпись и декодирование — firebase/php-jwt 7.2.1, фиксированный Key(..., HS256). Разрешён только HS256; alg=none/HS512 и неверная подпись отвергаются. sub — строковый положительный integer ID в пределах PostgreSQL INTEGER. iat/exp обязательные integer; проверяются срок, отсутствие будущего iat, exp > iat и длительность <= настроенного TTL; iss/aud должны точно совпадать. Допуска по времени нет, часы хостов должны быть согласованы. После проверки JWT пользователь повторно читается из Repository: удалённый пользователь не допускается. В request attribute authUser передаётся readonly AuthenticatedUser(id, username), на который смогут опираться API товаров/API импорта.

Все зарегистрированные /api-маршруты по умолчанию защищены, кроме POST login, GET health и временного GET _diagnostics. Middleware использует pattern найденного маршрута, чтобы encoded URL не обходил защиту. Неизвестный URL остаётся 404; будущие business endpoints не создаются заглушками ради авторизация. С API товаров готовы приватные GET /api/products и /api/products/{id}; они используют ту же защиту по умолчанию и проверены через production nginx/FPM. Проверенный JWT не хранится сервером и не отзывается отдельно; logout удаляет клиентскую копию, ключ можно ротировать с инвалидированием существующих токенов. SessionStorage/guard/interceptor относятся к Angular вход, сейчас документируется согласованное решение.

## Request ID и ошибки

RequestIdMiddleware генерирует 32 hex символа сервером на каждый запрос, игнорирует клиентский X-Request-ID, помещает ID в request attribute requestId и response header. ApiErrorHandler одинаково формирует JSON errors для auth/HTTP/unhandled/DB ошибок; 500/503 без stack trace, SQL/DSN и exception message. Для 5xx журналируются только requestId/status/class исключения. Тело запроса, Authorization, JWT, ключ, password hash, query и сообщения исключений не пишутся в application log. ID из лога совпадает с X-Request-ID ответа. Логи nginx/FPM также проверены на HTTP-прогоне; токен не передаётся в URL.

## Самостоятельная проверка

PowerShell из корня проекта:

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-auth-http.py
```

Нужны Python 3 без внешних пакетов, Docker Desktop Linux Engine и сеть для первой сборки. Если docker не найден, у обеих утилит есть `--docker 'docker'`. Не запускать две команды verify.py одновременно: у них общий disposable test project. HTTP-проверка имеет отдельный project product-import-auth-check.

Ожидаются код 0 и PASS. check/test проходят настоящие Slim/PostgreSQL тесты: login, usable token, password failures, отсутствие/подделка/истечение/неверные claims и алгоритм, удалённый пользователь, заголовки/encoded path, безопасные 400/401/415/500/503, request ID/логи. HTTP-скрипт собирает production nginx/FPM, поднимает отдельную tmpfs PostgreSQL в internal-сети, отдельную временную HTTP bridge-сеть со случайным loopback-портом, явно загружает fixtures и POST login через случайный loopback-порт; независимо проверяет HMAC-SHA256 и claims, затем передаёт тот же JWT на проверку PHP TokenService. Проверяет одинаковые 401, malformed JSON 400, MIME 415, отсутствие секретов в логах и удаляет только свои временные контейнеры/БД. JWT в вывод/JSON-отчёт не сохраняется. Результат — tmp/auth-http-check.json.

Для подготовленного demo-приложения с миграциями/fixtures, новым образом и JWT_SECRET можно выполнить:

```powershell
$body = @{username='fixture-user'; password='password'} | ConvertTo-Json
$result = Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8080/api/auth/login -ContentType application/json -Body $body
$result.tokenType
$result.expiresIn
$result.user
```

Ожидается Bearer, 3600, username fixture-user; токен доступен в $result.accessToken, не публикуйте его. Текущее рабочее приложение остаётся на прежнем образе, без миграций/fixtures и нового секрета — эта ручная команда требует явно подготовленного demo. Автоматические проверки выше полностью автономны и рабочие данные не меняют. Фактические результаты — статус.

## Источники

Использованы [официальная документация php-jwt](https://github.com/googleapis/php-jwt), [password_hash](https://www.php.net/manual/en/function.password-hash.php) и API фактически установленной версии. Это проверка backend авторизация, не готовность Angular login/health/limiter/товарного API следующих задач.
