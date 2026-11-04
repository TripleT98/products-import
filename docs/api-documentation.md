# OpenAPI и Swagger UI

[OpenAPI](openapi.json) — OAS3.0.3, JSON camelCase/nullable/DECIMAL strings/UTC.8 существующих операций:7 основных и optional diagnostics. Нет новых HTTP business endpoints. Контракт соответствует реализации, включая diagnostic503 собственного формата и PNG example paths; согласованные routes/query/DTO не менялись. Примеры синтетические: runtime IDs/JWT/timestamps отличаются.

## Доступ и политика

Production nginx обслуживает public `/docs/` и `/docs/openapi.json` на том же origin, что Angular/API. `/docs` перенаправляет на `/docs/`, неизвестный файл docs даёт404, без SPA fallback. В image копируются swagger-ui-dist5.34.1 JS/CSS/license с отдельным api-docs/package-lock.json; исходник schema один — docs/openapi.json, Docker context включает только этот файл из docs.

Нет CDN/online validator, queryConfigEnabled=false и persistAuthorization=false. Swagger token только в памяти страницы, refresh очищает авторизацию. Schema/public assets не раскрывают secrets/jobs/user data. API auth/ownership middleware остаётся неизменным; даже из Swagger protected GET без JWT→401, чужой/missing job→404. Public endpoints health/login и enabled optional diagnostics отдельно описаны в контракте. Template APP_DIAGNOSTICS_ENABLED=false; docs test намеренно включает его в disposable окружении, чтобы проверить существующую8-ю операцию.

На production deployment Swagger сейчас тоже public: это принятая документированная политика metadata. Защитные API проверки обязательны независимо от UI. Не вводить чужие JWT; извне доступ к сайту по default отсутствует, nginx bind127.0.0.1. Изменение доступа к Swagger потребует отдельного решения/deployment, не скрытого предположения.

## Ручной JWT запрос

После [запуска/миграций/fixtures](../README.md) открыть http://127.0.0.1:8080/docs/:

1. POST /api/auth/login → Try it out. JSON username=fixture-user/password=password (только demo fixtures) → Execute. Ожидается200 и accessToken.
2. Authorize → вставить токен без `Bearer ` → Authorize → Close.
3. GET /api/products → Try it out → Execute. Ожидается200, items и pagination; до авторизации401. При default fixtures total48, limit20, totalPages3. Установка другого HTTP_PORT автоматически сохраняется относительным server URL `/`.
4. POST /api/imports → Try it out → выбрать synthetic/свой .xlsx в file → Execute. Ожидается202/jobId, не готовые товары. GET status/issues с этим ID покажет очередной/завершённый job текущего пользователя. Для реальной обработки нужен worker.
5. Refresh Swagger: авторизацию необходимо ввести заново. Angular sessionStorage и Swagger in-memory auth независимы.

## Проверка документации

```powershell
python scripts/check-docs.py
python scripts/verify.py check
```

Ожидается код0/PASS. Docs runner создаёт ignored tmp/t28-openapi-venv, при первом запуске устанавливает openapi-spec-validator0.7.2. scripts/validate-docs.py использует отдельный docs/checks/check_openapi.py. Валидатор не импортирует проверку контрольной книги и не требует XLSX. Проверяются8 operations/19 examples,39 template env +документированная таблица/Compose/backend getenv, затем real response samples через OAS30Validator. Nullable/ref schemas разворачиваются локально; никакой schema/body не отправляется online validator.

Browser runner использует Playwright1.56.1/установленный Microsoft Edge и изолированный product-import-docs-check: настоящие production nginx/FPM/worker, PG/Rabbit, controlled image HTTP, own volumes/networks и random loopback port. В test bootstrap удаляется только синтетический worker-fixture пользователь в проверенной fresh test DB; затем настоящая console fixtures:load под www-data с command-scoped APP_ENV=development выполняется дважды.48 продуктов/96 attrs/48 images/fixture-user реальны, изменения не касаются working DB. Три synthetic XLSX rows дают2 created/1 error/1 image warning. Swagger выполняет real login/Authorize/GET/multipart, остальные HTTP ответы также читаются с реального API. Mock successful responses нет.

Фактически проверены protected401→JWT200, public docs/assets/health/diagnostics, products detail/invalid query400, upload202/queued/worker terminal/issues/missing404; response body/header/request ID соответствуют OpenAPI. AccessToken в сохранённых live samples заменён placeholder; настоящий JWT доказан успешным Swagger Authorization/API request. После refresh auth empty, запросов к внешним origins0. tmp/verification-docs.json, tmp/t28-live-responses.json, tmp/t28-docs-browser.log и tmp/t20-browser-tooling/swagger-jwt.png сохраняют диагностику (только synthetic test data/JWT).

## Источники

[Swagger UI installation](https://swagger.io/docs/open-source-tools/swagger-ui/usage/installation/), [configuration](https://swagger.io/docs/open-source-tools/swagger-ui/usage/configuration/) и [OpenAPI3.0.3](https://spec.openapis.org/oas/v3.0.3.html). Lock-файл фиксирует используемый UI, version/integrity не зависят от CDN при просмотре.


### Только OpenAPI

В Python-окружении с установленным `openapi-spec-validator==0.7.2`:

```powershell
python docs/checks/check_openapi.py
```

Ожидается `OpenAPI 3.0.3 valid; 8 operations; 19 examples valid`. Другой JSON-файл можно передать через `--spec`. Проверка контрольного XLSX остаётся отдельной утилитой `docs/checks/check_t01.py --xlsx <path>` и не нужна для проверки API или CI.
