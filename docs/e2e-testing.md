# Сквозная проверка приложения


## Запуск

PowerShell, Docker Desktop с Linux Engine, Python3, Node24/npm и установленный Microsoft Edge:

```powershell
python scripts/check-e2e.py
```

Ожидаются `1 passed`, `PASS: e2e real end-to-end…`, код0. Эквивалент при наличии Make — `make e2e-check`. При нестандартном Docker CLI: `python scripts/check-e2e.py --docker 'path/to/docker.exe'`.

Runner копирует e2e/package.json и package-lock.json в ignored `tmp/t26-e2e/runtime`, выполняет `npm ci --ignore-scripts --no-audit --no-fund`. Playwright1.56.1 и транзитивные версии/integrity зафиксированы в отдельном lock-файле. Application package/lock-файлы не меняются. Первому запуску необходим доступ к npm registry и Docker registry либо соответствующие caches. Браузер не скачивается автоматически: по умолчанию используется установленный Edge. При необходимости можно задать `E2E_BROWSER_CHANNEL` для другого уже установленного совместимого browser; В e2e проверен msedge/Windows; CI добавляет explicit chromium-headless-shell mode для CI (channel остаётся undefined), команды см. [CI](ci.md).

Не запускать два экземпляра check-e2e одновременно: они разделяют test project/runtime. `verify.py check` и `verify.py test` также запускать последовательно между собой. Их отдельный test project не совпадает с e2e.

## Что проверяется

1. Неавторизованный `/import` → login с returnUrl; неверный пароль даёт настоящий401, правильный возвращает на import. Logout и повторный deep link карточки вновь требуют вход.
2. Синтетический initial XLSX содержит25 valid products с внешними кодами E2E-0001…0025, ценами101…125, закупочной75, атрибутами Размер=0/Цвет=Синий; первые10 содержат controlled image URL. Worker остановлен: POST202/queued, настоящий GET products возвращает0. Запускается только worker проверенного test project; UI показывает processing→completed,25 created/0 failed/warnings. Refresh восстанавливает counters и пустой отчёт.
3. «К товарам» → список с importJobId, страницы20+5. Page2 сохраняется после refresh; name+price101…110 дают10 записей, фильтры сохраняются после refresh. Карточка первого товара показывает101.00/75.00/25.74, нулевой атрибут, local PNG с ненулевым naturalWidth и отдельный import context. Back сохраняет фильтры.
4. Повтор того же XLSX через UI:0 created/25 updated, всего25 уникальных кодов, прежний product ID, по одному изображению и три атрибута вместо дублирования (ссылки на фото также сохраняются как Доп. поле по контракту приложения). Имя нового PNG может изменяться: стабильность пути при повторном скачивании контрактом не задана.
5. Второй XLSX обновляет E2E-0001 (новое имя/описание,200.00/150.00/25.00, новые атрибуты L/Да и JPEG→PNG), создаёт E2E-0026 и отклоняет E2E-0027 с нулевой ценой. Настоящий image404 даёт warning, успешная картинка сохраняется. Итог:3 processed/1 created/1 updated/1 failed/1 warning, всего26 товаров; недопустимый код отсутствует. Report содержит warning row2/images/IMAGE_HTTP_ERROR и error row4/price/INVALID_PRICE.
6. После refresh проверяются данные и report из API, затем через каталог/фильтр/карточку проверяются новое имя/описание/цена/атрибуты, отсутствие прежнего Цвет=Синий, загрузка локального PNG и два issues общего widget. Context query/возврат/refresh сохраняются. Проверяется содержимое, а не только уведомление.

Условия ограничены Playwright timeouts: expect10s, actions10s, navigation20s, terminal45s, весь сценарий180s, процесс240s; retries0/skips0. Нет длинных фиксированных sleep. Server controlled slow images обеспечивают наблюдаемый processing; они не зависят от внешнего catalog.collant.ru.

## Отчёты и диагностика

`tmp/verification-e2e.json` и `tmp/t26-e2e/latest.json` содержат результат, counters и относительный путь `artifacts` последнего запуска. Каждый новый запуск имеет собственную папку `tmp/t26-e2e/runs/<UTC>-<id>/`, прошлые не удаляются. Там сохраняются:

- results.json (структурированные ошибки и шаги), html/index.html, browser.log;
- build.log, npm.log, services.log (собран перед down), verification.json;
- test-results: screenshot/trace.zip/error-context при failure, updated-card.png при success;
- runner-error.txt при ошибке запуска/теста, частичный browser.log при process timeout.

Открыть HTML после прогона:

```powershell
$run = Get-Content .\tmp\t26-e2e\latest.json -Raw | ConvertFrom-Json
$reportPath = Join-Path (Get-Location) ($run.artifacts + '/html')
node .\tmp\t26-e2e\runtime\node_modules\@playwright\test\cli.js show-report $reportPath
```

Ожидается локальный просмотр отчёта с шагами и ошибками. Если сбой произошёл до старта Playwright, HTML может отсутствовать: смотреть runner-error.txt/npm.log/build.log/services.log. Test traces могут содержать только synthetic credentials/JWT disposable test owner; артефакты остаются ignored tmp, не публикуются. Исходный пользовательский XLSX/изображения не используются.

## Изоляция и границы

Project `product-import-e2e-check`: собственные PostgreSQL/RabbitMQ tmpfs, uploads/images volumes и networks. Перед down/up проверяются имя,6 ожидаемых services, отсутствие external resources/bind mounts, internal tests network, DB name и единственный nginx port на random127.0.0.1. App/worker — production PHP, nginx — production Angular build; fixture image отдельный testing target. Test limits maxRows30/5 uploads сохраняют объём сценария (3 POST), production config/API не изменяются. В finally собираются логи и удаляются только volumes/containers/networks этого project. Images/cache и артефакты сохраняются. Рабочие8080/.env/БД не обновляются.

Проверены общие check/test и этот полный browser scenario. Ранее выполненные восстановление recovery/duplicate delivery/system retries и отчёт импорта ownership/report pagination остаются отдельными подробными suites; e2e не заменяет их unit/integration coverage и не повторяет fault injection в browser. Нагрузочные/многобраузерные прогоны не входят в e2e. CI добавлен в CI, см. [команды и ограничения](ci.md); чистый clone приёмка не выполнялся.
