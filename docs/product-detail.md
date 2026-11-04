# Карточка товара

Карточка загружает товар через ProductService и отображает поля, атрибуты, локальные изображения и необязательный отчёт выбранного импорта.

## Поведение

`/products/:id` показывает Material-карточку: название, id/externalCode, description, price/purchasePrice/discount, createdAt/updatedAt UTC, все attributes key/value и галерею images. Decimal/code/time остаются строками, атрибут со значением0 не скрывается. Пустые description/attributes/images имеют явный текст. Long unbroken strings и кириллица переносятся, описание сохраняет переносы. Вся пользовательская информация выводится interpolation, без innerHTML/unsafe bypass.

Компонент использует ProductService, input.required id и async pipe. loadProductDetail создаёт состояния loading/ready/not-found/error. HTTP404 отделён от остальных ошибок, malformed/out-of-range route id не отправляется в API. Ошибка не выводит raw body/stack, retry повторяет текущий запрос и убирает прежнее общее notification. switchMap отменяет старый запрос при route reuse; shareReplay не создаёт повторов из template. HttpClient/.subscribe() в компоненте нет.

Галерея загружает только image.path внутри /images/, source image.url показывается текстом и не используется для скачивания. localImagePath отвергает external/protocol-relative/traversal/backslash/malformed paths. Настоящий load error заменяет img текстовой заглушкой без повторной загрузки другого URL и без цикла ошибок. При новом запросе состояние broken images очищается. Сервер остаётся источником порядка изображений, атрибутов и валидации DTO.

«К списку товаров» — native RouterLink /products с сохранением page/limit/name/price_min/price_max/importJobId. Фильтры восстанавливает список товаров из URL. Прямой URL без query возвращает на обычный список. При наличии importJobId общий виджет показывает состояние и отчёт выбранной задачи; без этого параметра polling импорта отсутствует.

## Проверки из PowerShell

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-product-detail-ui.py
python scripts/check-products-ui.py
```

Ожидаются exit0 и PASS. Check выполняет frontend lint/strict app+spec typecheck/87 tests/production build и backend365 tests/2168 assertions/static/style/schema/fixtures. Test повторяет tests. Добавлены16 Angular/HttpTestingController cases карточки: states/data/precise strings/zero/text safety/local path/back query, missing fields/images, broken image event, malformed IDs,404/error/retry и cancellation при route reuse. Shell tests теперь явно завершают HTTP requests настоящей карточки; проверки вход/NgRx/список товаров сохранены.

Browser runner (`make product-detail-ui-check`) требует Docker Linux Engine, Python, host Node/npm и установленный Microsoft Edge, как вход/список товаров. Использует прежний ignored Playwright1.56.1 tooling. Создаёт отдельный product-import-detail-ui-check: production nginx/FPM и disposable PG/fixture owner/RabbitMQ, own networks/volumes, только nginx на случайном loopback port. Main .env/контейнеры/данные не используются. Cleanup проверенного проекта выполняется finally, worker не запускается.

Test-only detail-fixture.php после45 list fixtures дополняет LIST-0011: длинное кириллическое название/описание, безопасно отображаемый HTML-подобный текст,3 атрибута (включая0/long value),2 настоящих GD-generated PNG480x300 и1 отсутствующий local image. Оригинальный XLSX/внешние изображения не используются, обычные CatalogFixtures неизменны. Скрипт проверяет:

- Прямой guarded URL и переход из списка с настоящим backend login/API.
- Все поля/атрибуты, точные цены,0, long text и отсутствие исполнения HTML.
-2 локальных PNG loaded/naturalWidth480, broken image fallback, отсутствие внешних image requests; отдельно товар без images.
- Сохранение query через явную кнопку возврата в фильтрованный список.
- Настоящий404, controlled HTTP500/retry и задержку настоящего ответа для loading. Все успешные payloads — real API/PG;500 и delay управляются browser interception.
- Узкий экран390px без horizontal document overflow, desktop/mobile screenshots визуально проверены.

Отчёт tmp/verification-detail-ui.json; screenshots tmp/t20-browser-tooling/product-detail.png и product-detail-mobile.png. локальный отчёт проверки, фактический статус. список товаров browser regression повторно проверяет45 rows/page filters/card-back/fast search.

## Проверка вручную

В актуальном подготовленном demo (nginx/app/JWT/migrations/fixtures по README/auth docs) войдите, откройте /products?page=2&limit=20&name=... и выберите товар. Карточка показывает полный API detail; «К списку товаров» восстанавливает те же query filters. Прямой /products/{id} работает после refresh; неизвестный id показывает «Товар не найден». У товара без images виден текст «Изображений нет.», при недоступном local file — «Изображение недоступно». Для автономной воспроизводимой проверки используйте runner выше: он сам готовит отдельное demo и убирает его после проверки.

## Ограничения

## Просмотр изображений

`ImageCarousel` получает массив `ProductImage` и название товара. Отображает одно изображение, боковые стрелки, счётчик и источник. Поддерживает клавиши ←/→; на границах кнопки блокируются. Пустой массив и ошибка загрузки показывают отдельные сообщения. При смене массива выбор сбрасывается. В src разрешены только безопасные локальные пути `/images/`; исходный URL выводится текстом. Автопрокрутки, масштабирования и полноэкранного режима нет.
