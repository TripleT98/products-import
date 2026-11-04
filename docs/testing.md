# Тестирование

Проверки используют отдельные Compose projects с тестовыми учётными данными. Рабочая БД и загрузки приложения не используются. PHPUnit bootstrap проверяет окружение перед подключением.

## Общий прогон

Из корня репозитория при работающем Docker с Linux containers:

```powershell
python scripts/verify.py check
python scripts/verify.py test
```

`check` выполняет Composer validation, PHPStan level 5, PHP CS Fixer, PHPUnit, frontend ESLint, typecheck, unit tests и production build. `test` запускает backend- и frontend-тесты. Обе команды применяют миграции, проверяют схему и повторную загрузку fixtures. Тестовая PostgreSQL использует временное хранилище; cleanup выполняется и после ошибки.

Не запускайте `check` и `test` одновременно: они используют общий тестовый проект. Для нестандартного расположения Docker CLI передайте `--docker <path>`. Первый запуск требует сети для образов и пакетов, далее используется кэш.

Для локальных frontend-проверок при Node.js 24 и npm:

```powershell
npm ci --prefix frontend
npm run check --prefix frontend
```

## Покрытие

- Хранение: миграции, fixtures, CRUD Repository, уникальность, каскады и транзакционный rollback.
- API: JWT, фильтры, пагинация, владение задачами, безопасные ошибки и rate limit.
- XLSX: ZIP/OOXML, заголовки, типы, формулы, decimal parsing, атрибуты и дубликаты кодов. Тестовые книги синтетические.
- Изображения: управляемый HTTP-сервер, SSRF, таймауты, лимиты, проверка содержимого, PNG и очистка файлов.
- Импорт: атомарность строки, повторная доставка, lease/fencing, retries и перезапуск worker.
- Frontend: NgRx, guard/interceptor, polling, состояния страниц, отчёт, безопасный вывод и карусель.

Число тестов и результаты берите из текущего вывода команд.

## Дополнительные сценарии

```powershell
python scripts/check-worker.py
python scripts/check-upload.py
python scripts/check-import-api.py
python scripts/check-recovery.py
python scripts/check-frontend.py
python scripts/check-auth-ui.py
python scripts/check-products-ui.py
python scripts/check-product-detail-ui.py
python scripts/check-import-ui.py
python scripts/check-report-ui.py
python scripts/check-e2e.py
python scripts/check-docs.py
python scripts/check-workflow.py
python scripts/check-docker-build.py
```

Ожидается код завершения 0. Сценарии используют изолированные сервисы. Подробнее: [worker](import-worker.md), [восстановление](import-recovery.md), [e2e](e2e-testing.md), [Swagger](api-documentation.md).

Браузерные проверки по умолчанию используют Edge. Chromium headless shell и CI-команды описаны в [CI](ci.md). Артефакты записываются в игнорируемый `tmp/`; trace может содержать тестовые запросы и токены, поэтому его нужно проверить перед публикацией.

Статическая проверка workflow не эмулирует GitHub Actions. `python scripts/check-git-safety.py` ищет известные признаки секретов, но не гарантирует отсутствия любых чувствительных данных. Приёмочные сценарии описаны в [acceptance](acceptance.md), запуск — в [README](../README.md).
