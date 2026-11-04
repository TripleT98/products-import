# Вход, сеанс и защита маршрутов


## Поведение

`/login` содержит Material reactive form: username/password, autocomplete, обязательные поля, pending/disabled submit и защита повторной отправки. AuthService делает прежний POST /api/auth/login. Неверный пароль показывает фиксированное сообщение и оставляет форму/returnUrl без redirect loop. Успех очищает password field, создаёт сеанс и возвращает на запрошенный route с query/fragment, по умолчанию /products. Return URL ограничен известными /products и /import routes; внешние URL, /login и неоднозначные paths не принимаются.

AuthSessionService хранит только accessToken/user/expiresAt в sessionStorage по ключу product-import.auth. Refresh в той же вкладке восстанавливает сеанс. Пароль не сохраняется, localStorage/cookies для токена не используются. Срок ограничен минимумом JWT exp и expiresIn ответа; exp декодируется только для local expiry hint. Подпись/срок/identity по-прежнему проверяет сервер, guard не заменяет backend authorization. Повреждённый/истёкший cache удаляется. При запрещённом sessionStorage вход сообщает невозможность сохранить сеанс, скрытого memory fallback нет.

Logout удаляет клиентский сеанс и переводит на /login. Авто-таймер при expiry очищает его и переводит на login с returnUrl; guard также проверяет срок при navigation. Скрытая/усыплённая вкладка может задержать timer, но следующий guard/API token lookup срок проверит. Backend JWT stateless: уже скопированный токен logout на сервере не отзывает, refresh token не добавлен.

CanActivateFn защищает /products, /products/:id, /import и /import/:jobId. Anonymous navigation возвращает UrlTree на /login с безопасным returnUrl. Login/wildcard публичны. Navbar показывает username и «Выйти» при активном сеансе. Guard возвращает URL, не вызывает imperative navigation внутри себя.

Functional authInterceptor нормализует URL относительно document.baseURI, сравнивает origin и /api/ prefix. Bearer добавляется только API приложения; login/health публичны и токен автоматически не получают. Внешние origins, protocol-relative external URL, /images и похожие /api-other paths не получают credentials и не запускают глобальную обработку ошибок. Multipart headers/boundary не меняются.

401 защищённого API очищает matching сеанс и переводит на login; 401 от login остаётся ошибкой формы. Поздний401 для старого токена не удаляет новый успешный login.500/5xx приложения показывают общий dismissible alert «Сервис временно недоступен. Попробуйте позже.» без raw response/stack. HttpErrorResponse передаётся вызывающему коду, не превращается в success и не вызывает retry. API/контракт/backend не изменены; HttpClient по-прежнему отсутствует в компонентах.

## Повторить проверки

PowerShell, Docker Desktop Linux Engine и Python:

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-auth-ui.py
```

Каждая команда заканчивается exit0/PASS. Check: strict app/spec typecheck, ESLint,43 frontend tests, production build; backend365 tests/2168 assertions/static/style/schema/fixtures. Test повторяет unit/integration suites. Frontend tests содержат18 API-adapter cases,22 session/interceptor/return-url cases и3 Router/Material/form cases. Проверены storage refresh/malformed/expiry/deadline tampering/storage denial, logout, timer, API-only origins, login401, late401, server error; UI form double-submit/500 safe banner/wrong password/return/guard послеlogout.

`check-auth-ui.py` / `make auth-ui-check` требует также установленные Node/npm и Microsoft Edge. Для Playwright1.56.1 runner один раз устанавливает только test tooling в игнорируемый tmp/t20-browser-tooling; frontend package/lock не меняются. Сам браузер не скачивается. Первый запуск требует npm network. Тестовый Edge запускается headless отдельным процессом/профилем, обычные окна/профили пользователя не используются.

Runner собирает production nginx/FPM и отдельный PG/RabbitMQ/fixtures в product-import-auth-ui-check. Только nginx публикует случайный loopback port, БД/брокер/FPM не публикуются. Свои internal test network, отдельная HTTP bridge network и disposable volumes; main .env/данные не используются. Реальный fixture user worker-fixture/worker-test-password находится только в test DB.

Browser test проходит anonymous deep link→guard/login→реальный incorrect password401→реальный login200→возврат /products/42?page=2; проверяет backend API200 с полученным токеном, reload той же вкладки, logout/removal, повторный guard и local expiry. Для expiry ускорены только часы браузера через Playwright clock, серверные часы/JWT settings не меняются. Проверка API200 использует browser fetch с явно переданным токеном для независимого доказательства его пригодности; API-only interceptor/401/500 проверены Angular HttpTestingController, без добавления тестовых production endpoints или продуктовых запросов в placeholder pages.

Runner удаляет только свой project/networks/volumes в finally. JWT/пароли сеанса не печатаются в отчёт, screenshots/traces с credential data не сохраняются. tmp/verification-auth-ui.json содержит только результаты. локальный отчёт проверки, статус.

## Проверка вручную

Нужен новый образ nginx/app, миграции, fixtures и JWT_SECRET в подготовленном demo. Старый рабочий8080 автоматически не обновлялся. Автономный runner выше уже полностью подготавливает и проверяет отдельное приложение.

В подготовленном demo:

1. Откройте /products/42?page=2 без сеанса: получите форму /login с returnUrl.
2. Неверный пароль: сообщение «Неверное имя пользователя или пароль.», без redirect loop. Для штатных CatalogFixtures верный demo-вход fixture-user/password (не worker-fixture из browser test).
3. Верный вход: возврат /products/42?page=2, видны username/«Выйти» и временная карточка42. Refresh сохраняет маршрут/сеанс.
4. «Выйти» удаляет сеанс. Прямая ссылка /import снова ведёт наlogin. Не публикуйте значения sessionStorage или Authorization.

Срок по умолчанию3600s задаёт backend JWT_TTL_SECONDS. Для быстрого воспроизводимого expiry используйте runner с browser clock, а не меняйте рабочую конфигурацию/часы.

## Ограничения

SessionStorage доступен JavaScript приложения; XSS может прочитать bearer. Это выбранная схема, без httpOnly cookie/refresh/revocation. Не добавлены регистрации/смена пароля/авторизация через сторонние API. Локальные guards/expiry — удобство клиента, сервер остаётся обязательной проверкой. Прежние49 npm audit записей из Angular API не исправлялись; новых app dependencies нет.

Первый browser runner пытался загрузить отдельный Docker browser image и был остановлен из-за задержки; он не засчитан как проверка. Итоговый прогон использует установленный Edge, успешный результат записан отдельно. Рабочие контейнеры/БД/исходный XLSX не затронуты.

Реализация использует [Angular route guards](https://angular.dev/guide/routing/route-guards) и [functional HTTP interceptors](https://angular.dev/guide/http/interceptors), API сверены с установленным Angular22 и фактическими тестами.
