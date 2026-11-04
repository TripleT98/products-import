# GitHub Actions и production build

Workflow [ci.yml](../.github/workflows/ci.yml) предназначен для `push`, `pull_request`, ручного `workflow_dispatch` без фильтра по имени ветки. CI настроен, удалённый запуск не проверен: результат конкретного запуска проверяется в GitHub Actions.

## Состав workflow

Один последовательный job `checks` на ubuntu-24.04, timeout35min. Его steps:

1. Checkout без сохранения credentials; Python3.13; Node24.22.1 (как Docker frontend tooling).
2. `python scripts/check-workflow.py`: actionlint1.7.7 и ShellCheck0.10.0 из Docker image, закреплённого digest, read-only mount проекта, network=none. Проверяет YAML, GitHub expressions/schema и встроенные shell-команды. Это статическая проверка, не эмуляция GitHub API.
3. `python scripts/verify.py check`: Composer strict/PHPStan5/style, PHPUnit unit/integration на isolated PostgreSQL, migration/schema/fixtures twice, frontend ESLint/strict typecheck/Vitest/build. Test job не требует RabbitMQ для PHP tests: queue integration проверяется e2e с настоящим брокером на следующем шаге.
4. `npm ci --prefix e2e --ignore-scripts --no-audit --no-fund`, затем locked Playwright CLI `install --with-deps --only-shell chromium`. Browser version берётся из locked Playwright1.56.1; загружается только Chromium Headless Shell, зависимости приложения не обновляются. Режим E2E_BROWSER_CHANNEL=chromium-headless-shell оставляет Playwright channel незаданным, что выбирает bundled headless shell; default msedge сохранён.
5. `python scripts/check-e2e.py` с `E2E_BROWSER_CHANNEL=chromium-headless-shell`, `CI=true`: настоящий [e2e scenario](e2e-testing.md), production app/worker/nginx + own PG/RabbitMQ/controlled HTTP. Host browser соединяется только с dynamic loopback nginx port.
6. `python scripts/check-docker-build.py`: production targets app/worker/nginx из основного compose.yaml, только build, без up/push. Отдельные tags product-import-ci-build-backend/frontend:t03 сохраняют согласованные имена Compose; сборка использует текущие исходники. Скрипт проверяет project/production target/tag prefix до build, не перезаписывает working images.
7. `upload-artifact` с `if: always()`: logs, verification JSON, e2e per-run reports/screenshots/traces, retention7days. Обычные ошибки предыдущего шага делают job failed и не подавляются; artifact upload всё равно предпринимается. Принудительное завершение runner может помешать upload/cleanup.

GitHub Actions закреплены полными commit SHA с комментарием версии: checkout7.0.1, setup-python7.1.0, setup-node7.1.0, upload-artifact7.0.2. Permissions contents:read, без repo/package write, без secrets или registry login. Используются только публичные test credentials из .env.test.example и build placeholders .env.example. Нет pull_request_target, deployment, Docker push, commit/push. Bash с явным shell включает pipefail, поэтому tee не скрывает ошибки проверок. Concurrency отменяет устаревший run того же ref/workflow; каждый hosted job имеет отдельный Docker daemon.

## Локальное повторение

PowerShell в корень репозитория, Docker Desktop Linux Engine, Python3.13, Node24/npm:

```powershell
python scripts/check-workflow.py
python scripts/verify.py check
npm.cmd ci --prefix e2e --ignore-scripts --no-audit --no-fund
node e2e/node_modules/@playwright/test/cli.js install --with-deps --only-shell chromium
$env:E2E_BROWSER_CHANNEL = 'chromium-headless-shell'
$env:CI = 'true'
python scripts/check-e2e.py
python scripts/check-docker-build.py
```

Ожидаются код0/PASS для workflow/check/e2e/build; e2e —1 passed с6 steps. Для обычного Edge mode в новой PowerShell-сессии достаточно прежней команды e2e без E2E_BROWSER_CHANNEL. Переменные выше действуют только в текущем shell. На Linux npm вместо npm.cmd и `export E2E_BROWSER_CHANNEL=chromium-headless-shell CI=true`. Playwright --with-deps на Linux устанавливает системные browser libraries, на Windows скачивает совместимые binaries.

При наличии Make доступны workflow-check/check/e2e-check/docker-build-check; установка Chromium выполняется указанным CLI отдельно. Python scripts принимают `--docker` для нестандартного пути. Не запускать несколько копий одной проверки одновременно: Compose test projects/runtime общие в пределах одного daemon/workspace. Рабочие контейнеры/volumes/.env не используются. Docker build оставляет новые CI images/cache; tests удаляют свои containers/networks/volumes.

## Результаты и ограничения

Локальные reports: tmp/verification-workflow.json, verification-check.json, verification-e2e.json, verification-docker-build.json. E2E latest/run paths описаны в [e2e](e2e-testing.md). Workflow сохраняет tmp/ci logs и только synthetic diagnostics в artifact `ci-diagnostics-<run_id>-<attempt>`; runtime/node_modules/workbooks/пользовательский XLSX/.env не входят. Test JWT/credentials в trace относятся к disposable БД.

Локально выполнены команды workflow на Windows, включая Chromium Headless Shell; Linux containers backend/frontend/PG/Rabbit настоящие. GitHub-hosted Ubuntu runner, checkout/setup actions и upload artifact API здесь не запускались. Host Node24.21.0 соответствует major engine, а CI/Docker фиксируют24.22.1; host Python3.13.9. Это не свидетельство зелёного удалённого CI. Полный первый запуск на GitHub также зависит от доступности registry/browser CDN, Docker/Compose на runner и Actions permissions/billing приватного репозитория.

Источники настройки: [синтаксис workflow](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax), [официальная Playwright CI-инструкция](https://playwright.dev/docs/ci), [actionlint installation](https://github.com/rhysd/actionlint/blob/main/docs/install.md). Версии Actions сверены с официальными release/commit pages, SHA записаны в workflow.
