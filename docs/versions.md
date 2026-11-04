# Версии и совместимость

Конкретные версии зависимостей фиксируются в `backend/composer.lock`, `frontend/package-lock.json`, `e2e/package-lock.json` и `api-docs/package-lock.json`. Версии и digest образов находятся в Dockerfile и Compose. Таблица описывает выбранную совместимую линию компонентов, а не автоматически обновляемый перечень последних релизов.

| Компонент | Выбор | Основание |
|---|---|---|
| PHP | 8.4.x, минимум 8.4.1 | Поддерживаемая ветка; нижняя граница Symfony 8.1 / PHPUnit 13 |
| Composer | 2.11.1 | Composer runtime API 2, нужен Doctrine; [официальная загрузка](https://getcomposer.org/download/) |
| Slim / PSR-7 | 4.15.3 / slim/psr7 1.8.0 | PHP 8.4 допускается require обоих пакетов |
| Doctrine ORM / DBAL / Migrations | 3.7.6 / 4.5.0 / 3.9.8 | ORM и Migrations допускают DBAL ^4; Migrations допускает ORM 3 |
| Messenger / AMQP bridge | 8.1.10 / 8.1.9 | PHP >=8.4.1; bridge требует Messenger ^8.1 и ext-amqp |
| Symfony Console / Lock / RateLimiter | 8.1.10 | Одна minor-линия компонентов; Lock требует DBAL >=4.3, выбран 4.5.0 |
| PhpSpreadsheet | 5.10.0 | PHP ^8.2 и перечисленные ниже extensions |
| brick/math | 1.0.0 | PHP ^8.2, точная decimal/rational арифметика |
| firebase/php-jwt | 7.2.1 | PHP ^8.0, HS256 для фиксированного алгоритма |
| PHPUnit | 13.4.5 | PHP >=8.4.1 |
| PHPStan / PHP CS Fixer | 2.3.4 / 3.95.27 | PHP ^8.0 допускается; Fixer допускает Symfony 8, PHPStan level >=5 |
| Node.js | 24.22.1 | Удовлетворяет Angular engine ^24.15.0; [политика LTS](https://nodejs.org/en/about/previous-releases) |
| Angular core/compiler/compiler-cli/common/forms/platform-browser, CLI/build | 22.0.0, одинаковая версия | Framework/compiler согласованы, стандартный builder Angular |
| Material / CDK | 22.0.0 / 22.0.0 | Материал требует именно этот CDK, Angular ^22 допускается |
| NgRx Store / Effects | 22.0.0 / 22.0.0 | Effects требует Store 22.0.0, оба Angular ^22 |
| TypeScript / RxJS | 6.0.2 / 7.8.2 | Angular TS >=6.0 <6.1, RxJS пересечение Angular/NgRx включает 7.8.2 |
| Vitest / Playwright Test | 4.0.8 / 1.56.1 | Angular build peer Vitest ^4.0.8; engines обоих допускают Node 24 |
| PostgreSQL | 18.6 | Поддерживаемая стабильная версия в [официальной таблице](https://www.postgresql.org/support/versioning/) |
| RabbitMQ | 4.4.0 | Релиз 20.10.2026, community support до 31.07.2027 по [официальной таблице](https://www.rabbitmq.com/release-information); AMQP 0-9-1 через ext-amqp |
| nginx | 1.30.6 stable | [Официальная страница релизов](https://nginx.org/en/download.html) |
| Docker Compose | Compose plugin v2, минимум 2.24 | Нужен Docker Engine с Linux containers; [официальная установка](https://docs.docker.com/compose/install/) |
| Документация / проверка | OpenAPI 3.0.3 / openapi-spec-validator 0.7.2 | Валидатор из официального PyPI в отдельном временном venv, не dependency приложения |

## Проверка

Для приложения используйте установку по lock-файлам и сборку внутри контейнеров:

```powershell
python scripts/verify.py check
python scripts/check-docker-build.py
```

Эти команды проверяют Composer manifest, PHPStan, стиль, тесты и сборку. PHP extensions и Composer platform requirements проверяются в backend-образе; не обходите их через `--ignore-platform-reqs`.

В [package-metadata.json](checks/package-metadata.json) сохранён снимок метаданных прямых зависимостей. Повторное чтение реестров требует сети:

```powershell
python docs/checks/check_versions.py --report "$env:TEMP/product-import-package-metadata.json"
```

Проверка диапазонов совместимости сохранённого снимка использует пакет `semver`. При доступности этого пакета через обычный Node resolution:

```powershell
node docs/checks/check_compatibility.cjs
```

При необходимости передайте первым аргументом путь к установленному модулю `semver`. Эта проверка не заменяет npm/Composer solver, тесты и production-сборку.

## Платформа

Backend использует PHP 8.4 с расширениями для PostgreSQL, AMQP, XML, ZIP, GD, cURL и PCNTL. Worker выполняется в Linux-контейнере. Angular, CLI и compiler должны иметь согласованные версии; Material требует соответствующий CDK, NgRx Effects — соответствующий Store.

Swagger UI поставляется локально вместе с лицензией. Manrope поставляется локально в frontend с SIL Open Font License. Не удаляйте лицензии сторонних компонентов при подготовке дистрибутива.

Изменение версии требует обновления lock-файлов, проверки platform/peer constraints, тестов и сборки. Успешная проверка прямых диапазонов не гарантирует транзитивную совместимость или отсутствие уязвимостей.
