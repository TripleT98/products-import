# Angular: модели, маршруты и API-адаптеры

Типизированные модели, API-адаптеры и lazy routes Angular. Авторизация описана отдельно в [frontend-auth](frontend-auth.md).

## Компоненты

- `frontend/src/app/models/`: Product/ProductDetail/ProductAttribute/ProductImage/ProductQuery/ProductPage, ImportAccepted/ImportStatus/ImportJob/ImportIssue/IssuePage, Pagination/Page/PageQuery, LoginRequest/LoginResponse/AuthUser и ApiErrorResponse/ErrorDetail.
- Decimal price/purchasePrice/discount, externalCode и UTC timestamps сохраняются строками без Number/Date преобразований. Nullable totalRows/timestamps/fatalError и issue context явно отражены. Product list не требует attributes/images, detail требует оба массива. ErrorDetail содержит field/code/message; отсутствующие optional query values не отправляются.
- `core/api/ProductService`: GET /api/products с page/limit/name/price_min/price_max и GET detail. `ImportService`: multipart POST с единственным file, GET status и issue page. `AuthService`: JSON login. Все возвращают Observable типизированного DTO, работают с same-origin /api. HttpClient предоставлен через app.config.ts и используется только адаптерами, не компонентами. Ошибки и headers проходят как HttpErrorResponse без подмены успехом/автоматического retry. Runtime-валидация чужого JSON не добавлена: generic HttpClient сам её не выполняет.
- `app.routes.ts`: lazy standalone /login, /import, /import/:jobId, /products, /products/:id; / перенаправляет на /products, wildcard показывает «Страница не найдена». Typed required string inputs связаны с route params через withComponentInputBinding, обновляются при повторном использовании компонента.
- Material toolbar/buttons/cards, общая графитовая тема с бежевыми акцентами, responsive navigation, active aria-current и skip link. Нет внешних fonts/CDN. Placeholder pages показывают заголовки/ID и назначение раздела; не запрашивают и не подделывают данные.
- strict/strictTemplates и остальные compiler checks сохранены. Добавлена npm typecheck: tsc --noEmit для app и spec. npm check выполняет lint/typecheck/test/production build. `any`, bypass strict и broad ignores не добавлены.

Material/CDK22.0.0 зафиксированы в package/lock, peers сверены через npm view с установленным Angular22/RxJS7.8.2; npm ci и production build прошли в locked Node24 Docker image. Используется существующий штатный Angular builder, самостоятельной настройки Vite нет. Конфигурация опирается на [официальный HTTP testing guide](https://angular.dev/guide/http/testing) и [theming guide Angular Material](https://github.com/angular/components/blob/main/guides/theming.md).

## Проверка из PowerShell

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-frontend.py
```

Каждая команда: exit0/PASS. Общие check/test:365 PHPUnit tests/2168 assertions и20 frontend tests. Check дополнительно выполняет backend static/style/Composer, frontend ESLint/typecheck/production build, migrations/schema/fixtures. Frontend production initial bundle309.08kB, семь lazy chunks, без budget warnings. Frontend tests используют HttpTestingController и настоящий Angular Router/Material в jsdom.

HTTP adapter cases проверяют exact paths/methods/query, спецсимволы name и precision strings, defaults/empty page, detail attributes/images, FormData file name/type/size и отсутствие ручного Content-Type/boundary/owner, nullable status/fatal job/report context, login JSON и error/header propagation. Два route cases проверяют все пять feature pages, wildcard/root redirect/active navigation и обновление id при reuse.

`scripts/check-frontend.py` / `make frontend-check` собирает настоящий production nginx в product-import-frontend-check. Без host ports, volumes и рабочего .env; отдельная internal network. Проверяет шесть прямых URLs (включая wildcard), одинаковый index при refresh и200 для всех emitted JS/CSS, включая lazy chunks. API здесь не вызывается: test nginx resolver app указывает на localhost исключительно для загрузки конфигурации. Реальный backend отдельно проверен API импорта/восстановление. Runner убирает свой контейнер/network в finally. Отчёт: tmp/verification-frontend.json; локальный отчёт проверки.

Для интерактивного просмотра только frontend в отдельном локальном контейнере (не затрагивает основное приложение):

```powershell
$docker = 'docker'
& $docker build -f docker/frontend/Dockerfile --target production -t product-import-frontend-preview:t19 .
& $docker run --rm --name product-import-frontend-preview-t19 --add-host app:127.0.0.1 -p 127.0.0.1:8091:80 product-import-frontend-preview:t19
```

Оставьте терминал работающим, откройте http://127.0.0.1:8091/products, /login, /import, /products/42, /import/123e4567-e89b-42d3-a456-426614174000. Видны Material-меню и соответствующие временные страницы; refresh сохраняет маршрут. Ctrl+C останавливает/удаляет preview. Порт8091 должен быть свободен. Preview-команды приведены для ручного запуска; автоматическая проверка использовала тот же Dockerfile в контейнере без host port.

## Ограничения

`npm audit --json` на исходном testing image и новом lock дал одинаковые49 записей:2 low/35 moderate/10 high/2 critical. Новых affected package names после Material/CDK нет. Audit не является чистым; автоматический audit fix/force и внеплановые updates не выполнялись. Локальные отчёты tmp/t19-audit-before.json и tmp/t19-audit-after.json; summary сохранён в свидетельстве. Разбор прежних advisories требует отдельного обновления зависимостей с проверкой совместимости.

Рабочие контейнеры8080/.env/БД/брокер не обновлены. Для показа нового frontend в основном приложении понадобится явная пересборка nginx. Исходный XLSX не использовался, backend API/схема/зависимости не менялись.
