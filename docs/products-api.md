# API товаров API товаров

Основа — API товаров, R03/R09 и неизменный [OpenAPI](openapi.json). авторизация готова. Реализованы только GET /api/products и GET /api/products/{id}, DTO/Repository/сервис чтения и проверка локальных изображений. Запись через HTTP, Angular-страницы и импорт не добавлялись.

## Параметры и ответы

Оба маршрута требуют Authorization: Bearer <JWT>. Проверка авторизация идёт до разбора параметров: без токена даже malformed page/id возвращают 401. Неверные параметры с правильным токеном → 400 INVALID_REQUEST; отсутствующий товар → 404 NOT_FOUND. Ответы содержат X-Request-ID, JSON errors и no-store по общим middleware.

| Параметр списка | Правила |
|---|---|
| page | По умолчанию 1; положительное целое base-10, максимум 2147483647 |
| limit | По умолчанию 20; 1…100, без silent clamping |
| name | trim, пустая строка не фильтрует; буквальная подстрока без учёта регистра |
| price_min/price_max | Неотрицательные decimal strings, точка, 0–2 дробных знака, максимум 9999999999999999.99; границы включительны; min <= max |

Для дробной части, если точка указана, требуется 1–2 цифры. Пустые/отрицательные/экспоненциальные/дробные page/limit, пустые/невалидные цены, перевёрнутый диапазон → 400. Повтор любого известного параметра (включая encoded имя) и bracket-array syntax отклоняются. Неизвестные параметры игнорируются. Ведущие нули числовых query значений допустимы, сравнения цен выполняются после канонизации строк без float. Невалидный UTF-8/NUL в name отклоняется. PostgreSQL LOWER + LIKE с ESCAPE ! обрабатывают %/_/! как буквальные символы; пользовательский текст передаётся bind-параметром.

Список имеет форму {items: Product[], pagination: {page, limit, total, totalPages}}. Product содержит id, externalCode, name, description, price, purchasePrice, discount, createdAt, updatedAt. Decimal значения возвращаются строками с двумя знаками; timestamps — UTC RFC3339 с Z. Список не загружает attributes/images. Порядок строго id ASC. Дальняя страница имеет пустой items и настоящий total; при отсутствии совпадений totalPages=0.

Карточка дополняет те же поля массивами attributes [{id,key,value}] и images [{id,url,path,position}]. Атрибуты key ASC, изображения position ASC/id ASC; пустые связи — []. Код остаётся строкой с ведущими нулями, значение атрибута 0 сохраняется. Цена/скидка не преобразуются в JSON number. path — публичный /images/..., физический путь сервера не возвращается. Если в БД нарушен этот инвариант, DTO даёт безопасный 500, без раскрытия пути. Это не реализация скачивания/очистки файлов загрузка изображений/импорт строки.

## Слои и SQL

ProductController принимает HTTP и вызывает ProductQueryService. ProductFilter валидирует raw query до потери информации о повторных параметрах и id пути. ProductRepository::searchPage строит один набор предикатов Doctrine QueryBuilder; clone выполняет COUNT(p.id), исходный query — ORDER BY p.id ASC + setMaxResults/setFirstResult. ProductView/ProductPage явно формируют данные, сущности Doctrine не сериализуются напрямую.

Список делает два товарных SQL SELECT (COUNT и ограниченная страница), не читает связи, не вызывает array_slice и не загружает всю таблицу. Карточка делает один запрос товара и по одному запросу двух коллекций; сортировка задана mapping. SQL тест перехватывает настоящие запросы DBAL Logging Middleware, сравнивает WHERE, проверяет LIMIT 5 OFFSET 5 и наличие только пяти Product в UnitOfWork. Это проверка исполненного SQL на PostgreSQL, не проверка строк исходника.

## Самостоятельная проверка

PowerShell из корня проекта:

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-auth-http.py --products
```

Ожидаются код 0 и PASS. Первые две команды используют отдельный test Compose с tmpfs PostgreSQL. Последняя собирает production nginx/FPM, создаёт project product-import-products-check с новой БД, отдельной HTTP-сетью, случайным loopback-портом и новым временным image volume. Fixtures создаются явно. Проверяются настоящий login, страницы 1/2 (20+20 разных ID из 48), повтор страницы, COUNT/включительные цены/поиск кириллицы, дальняя страница и пустой результат, связи карточки/decimal strings, 400/401/404. PNG из карточки реально скачивается через nginx; проверяются Content-Type image/png, nosniff и точные байты искусственного файла. Временные контейнеры/сети/image volume удаляются в finally. Рабочие env/БД/volumes не используются. JWT не печатается и не попадает в отчёт. Результат — tmp/products-http-check.json.

Старый режим `python scripts/check-auth-http.py` сохраняет авторизация auth-проверки; новый --products включает их и добавляет API товаров. Не запускать одновременно две verify.py или два HTTP-прогона одного режима. Для полного Docker path используйте общий аргумент --docker 'docker'.

В подготовленном demo-приложении с новым backend-образом, миграциями схема БД, fixtures fixtures и JWT_SECRET можно проверить вручную:

```powershell
$body = @{username='fixture-user'; password='password'} | ConvertTo-Json
$login = Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8080/api/auth/login -ContentType application/json -Body $body
$headers = @{Authorization=('Bearer ' + $login.accessToken)}
$first = Invoke-RestMethod 'http://127.0.0.1:8080/api/products?page=1&limit=20' -Headers $headers
$second = Invoke-RestMethod 'http://127.0.0.1:8080/api/products?page=2&limit=20' -Headers $headers
$first.pagination
$first.items.Count
$second.items.Count
$id = $first.items[0].id
$detail = Invoke-RestMethod "http://127.0.0.1:8080/api/products/$id" -Headers $headers
$detail.attributes
$detail.images
(Invoke-WebRequest ('http://127.0.0.1:8080' + $detail.images[0].path)).StatusCode
```

Ожидается total=48/totalPages=3, 20 и 20 элементов с разными ID, у карточки два атрибута/одно изображение и HTTP 200 для PNG. После явного logout/без заголовка оба API возвращают 401. Статические изображения обслуживает nginx публично по известному пути, как в каркасе контейнеры; проверка JWT относится к API товаров. Текущее рабочее приложение на 8080 не обновлялось и остаётся прежним каркасом — для автономной проверки используйте три команды выше. Фактический статус.
