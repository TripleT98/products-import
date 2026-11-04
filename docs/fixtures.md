# Fixtures и CRUD хранения fixtures

Основа — R02/R15 и fixtures. схема БД уже содержит схему и миграции; fixtures добавляет операции хранения и искусственные данные для будущих API/UI. Публичный API не меняется.

## Набор

48 товаров FIX-0001…FIX-0048 с уникальными русскими названиями (Футболка/Платье/Жакет), ценами 100.00…4800.00, закупочной ценой 75% от продажи, скидкой 25.00. У каждого два атрибута (Размер/Серия, включая строку 0) и одна связь изображения. Итого 96 атрибутов, 48 image records и 3 общих искусственных PNG 24×24. Исходные URL имеют домен fixtures.invalid, загрузок из сети нет. Локальные пути /images/fixtures/t06-1.png…t06-3.png. Дата создания/обновления fixtures фиксирована: 2026-01-01T00:00:00Z. Числовые ID выдаёт БД; при повторной загрузке они сохраняются.

Пользователь fixture-user, пароль password — публичные тестовые данные. В users хранится bcrypt hash; проверяется password_verify. Вход/JWT реализованы авторизация/вход; для нового demo используйте fixture-user/password через /login или Swagger /docs/. Искусственные PNG хранятся в backend/fixtures/images и включаются в Docker-образы.

## Безопасный явный запуск

Загрузчик не вызывается автоматически при миграциях/старте. Нужен APP_ENV=development либо test. Он принимает только пустые четыре таблицы либо точное совпадение всего набора fixtures. Второй запуск — already loaded, без изменения записей/ID. Любые посторонние данные или редактирование fixtures приводят к отказу, без очистки/перезаписи БД. Reset/purge не предусмотрен: для нового набора используйте отдельную пустую demo/test БД. Файлы с несовпадающим содержимым тоже не перезаписываются.

Из корня проекта в PowerShell для отдельного тестового Compose:

```powershell
$docker = 'docker'
& $docker compose -p product-import-tests --env-file .env.test.example -f compose.test.yaml build backend-tests
& $docker compose -p product-import-tests --env-file .env.test.example -f compose.test.yaml up -d --wait db-test
& $docker compose -p product-import-tests --env-file .env.test.example -f compose.test.yaml run --rm -T --no-deps backend-tests php bin/console migrations:migrate --no-interaction
& $docker compose -p product-import-tests --env-file .env.test.example -f compose.test.yaml run --rm -T --no-deps backend-tests php bin/console fixtures:load
& $docker compose -p product-import-tests --env-file .env.test.example -f compose.test.yaml run --rm -T --no-deps backend-tests php bin/console fixtures:load
& $docker compose -p product-import-tests --env-file .env.test.example -f compose.test.yaml exec -T db-test psql -U product_import_test -d product_import_test -c 'SELECT (SELECT count(*) FROM products) AS products, (SELECT count(*) FROM product_attributes) AS attributes, (SELECT count(*) FROM product_images) AS images, (SELECT count(*) FROM users) AS users;'
& $docker compose -p product-import-tests --env-file .env.test.example -f compose.test.yaml down
```

Ожидаются loaded → already loaded и counts 48/96/48/1. Каждая run-команда создаёт временный контейнер; файлы изображений удаляются вместе с ним. Для тестов файлов/локального HTTP используйте общий check ниже. Не запускайте ручной test Compose одновременно с runner: он использует тот же отдельный project и очищает его.

В подготовленном demo-приложении с новой схемой и пустой БД явная команда — `docker compose exec -T --user www-data -e APP_ENV=development app php bin/console fixtures:load` (либо make fixtures). Она копирует PNG в IMAGE_DIR/fixtures, доступные через nginx по /images/fixtures/t06-1.png. Перед этим нужны новый backend-образ и миграция схема БД. Этот сценарий изменяет выбранную БД; в текущем рабочем приложении он не запускался. Чужую/изменённую БД загрузчик отклонит.

## CRUD

ProductRepository/ProductAttributeRepository/ProductImageRepository предоставляют save(entity, flush=true), remove(entity, flush=true), наследуют find/findBy/findOneBy/count. save используется для INSERT и UPDATE управляемой сущности, flush=false позволяет объединить изменения в одной Unit of Work. Эти методы сами не создают транзакцию бизнес-операции; атомарный upsert относится к запись товаров.

Product::updateDetails обновляет name/description/price/purchasePrice/discount и updated_at в UTC; external_code остаётся идентичностью, created_at сохраняется. ProductAttribute::update меняет key/value; ProductImage::update — url/path/position. Удаление дочерней записи через Repository синхронизирует коллекцию товара. Product::removeAttribute/removeImage поддерживают orphanRemoval; удаление товара удаляет зависимые записи. Удаление physical files пока не выполняется (общие файлы и сборка бесхозных — загрузка изображений/импорт строки).

## Проверки

```powershell
python scripts/verify.py check
python scripts/verify.py test
```

Ожидаются код 0 и PASS. Настоящая PostgreSQL, отдельные транзакции: CRUD всех трёх товарных таблиц с clear/reload, сохранение timestamps/decimal strings, каскады/orphanRemoval, нарушение уникальности через Repository. Проверки fixtures: counts/имена/цены/password hash, повтор с сохранением ID, отказ на чужих/изменённых данных и конфликтующем файле, запрет production-режима. Все 48 image records указывают на валидный локальный PNG; три файла дополнительно читаются по HTTP через временный PHP-сервер на loopback с ограниченным ожиданием старта и cleanup. Это не e2e приложения e2e и не проверка будущей загрузки изображений.

Runner после PHPUnit явно вызывает fixtures:load дважды в отдельной БД и очищает test project. Фактические результаты, [общий контур тестов](testing.md).
