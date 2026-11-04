# Повторная доставка и аварийное восстановление


## Что происходит после аварии

RabbitMQ хранит сообщение с ID до ack. При SIGKILL соединение закрывается, незавершённая доставка становится доступной следующему consumer. ImportJob остаётся processing с последним committed checkpoint. Новый worker не перехватывает живой lease: занятая доставка откладывается минимум на TTL+1 секунду. После естественного истечения lease claim создаёт новый fencing token/generation. Старый token больше не может фиксировать строки.

Worker перечитывает validation от заголовка, восстанавливая first-valid-code/duplicate правила. Immutable row ledger пропускает уже committed rows; товар, атрибуты, изображения, issues и counters фиксируются одной транзакцией строки. Ошибка изображения остаётся warning, некорректная строка — failed row. Terminal/missing delivery — no-op. Разные задачи могут обновлять один external_code; действует последняя успешная запись, уникальность и row-level locks сохраняются.

Обнаруженная и исправленная интеграционная ошибка: ProductWriter оборачивает исключение БД в ProductWriteException. RowImportService ранее превращал даже SQLSTATE08006 в PRODUCT_WRITE_FAILED и пропускал строку навсегда. Теперь ConnectionException, исчерпанные локальные RetryableException, SQLSTATE классов08/40/53/57/58 и потерянный lease выходят из row service после bounded cleanup prepared files. ImportHandler получает исходную причину, освобождает только свой matching lease и отдаёт message на bounded retry. Потерянный token использует TTL+1 задержку. Обычные ошибки записи/ограничений остаются ошибками строки. Секреты/raw SQL/stack не попадают в persisted report.

Messenger сохраняет прежние max3 retry после первой попытки, exponential delay и failed transport. При исчерпании с доступной БД job становится failed/IMPORT_FAILED, прежние committed rows/errors/warnings сохраняются. Фатальная книга завершается WORKBOOK_INVALID без retry loop. Нет обещания exactly-once доставки; подтверждается идемпотентность результатов задачи.

## Воспроизведение

PowerShell из корня проекта, Docker Desktop Linux Engine и Python:

```powershell
python scripts/check-recovery.py
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-import-api.py
```

Ожидается код0 и PASS каждой команды. Для recovery можно использовать `make recovery-check`, если Make установлен. Runner сам находит docker.exe; при необходимости `python scripts/check-recovery.py --docker 'docker'`.

Runner создаёт отдельный product-import-recovery-check: настоящие PG/RabbitMQ, testing fixture/server и отдельные production workers. Внутренняя сеть без host ports, свои disposable upload/image volumes, PG/Rabbit tmpfs. Рабочее .env и данные не используются. Перед down -v проверяются имя проекта, service set, test DB и происхождение volumes. Cleanup выполняется в finally. Входной XLSX синтетический, оригинал пользователя и внешняя сеть не нужны.

Книга содержит16 непустых строк и физический пропуск:13 корректных, invalid price и2 дубликата, один image404 warning. Управляемый /slow отвечает примерно за0.6s, позволяя наблюдать промежуточный checkpoint. Только тестовый overlay задаёт lease5s, retry100ms; production lease60s не меняется.

Проверки выполняются последовательно:

1. Один worker начинает задачу; после минимум4 результатов (включая errors/warning) выполняется настоящий `docker compose ... kill -s SIGKILL worker`, проверяется exit137. Снимок ledger/issues сохраняется. Worker перезапускается без ручного republish. Исходное сообщение восстанавливается, lease истекает естественно, generation растёт. Итог16 processed/13 created/3 failed/1 warning; префиксы committed ledger/issues побайтово совпадают по значениям.
2. Та же книга — новая задача с двумя сообщениями одного ID и `--scale worker=2`. Идут настоящие конкурирующие deliveries и delay retry занятого lease. Итог13 updated/3 failed/1 warning,16 ledger rows,13 товаров/изображений,26 атрибутов. Дополнительные две terminal deliveries ничего не меняют. Проверяются RabbitMQ ready, unacked и delay queues, а не только ready counter.
3. Однократный SQLSTATE08006 в trigger перед INSERT ledger row8, уже после flush товара/связей внутри той же транзакции. Товар/связи/checkpoint откатываются вместе; реальный Messenger retry доходит до корректного итога, лишней PRODUCT_WRITE_FAILED нет. Контрольный прогон до исправления воспроизвёл12 updated/4 failed вместо13/3.
4. Постоянный такой же сбой: ровно3 retry, failed queue содержит1 сообщение, job failed/IMPORT_FAILED с5 committed results,2 прежними row errors и1 warning; fatal issue добавлен, данные не потеряны. Failed message не переиздаётся автоматически.
5. Неисправимая книга: failed/WORKBOOK_INVALID,0 row results,1 persisted system issue, без retry; failed queue не растёт.

Четыре новых PHPUnit integration cases проверяют SQLSTATE08006/53000/57014/58000 с настоящими PostgreSQL trigger/rollback/изображением: после первой ошибки нет товара/checkpoint/issues/files, повтор той же строки успешно завершается. Прежние проверки ordinary row failures сохранены.

## Границы гарантии

Восстановление требует работающего consumer и сохранённых брокера/БД/private XLSX; после гибели владельца ожидание может составить TTL+retry delay. Lease обязан покрывать одну блокирующую операцию, в том числе начальный scan; максимальные книги/длительная нагрузка не профилировались. Retry ограничен на сообщение, не глобально на job.

При длительном полном отказе БД failed transport сохраняет ID, но записать terminal status в недоступную БД нельзя. После восстановления такой job требует операторского разбора/повторной доставки; автоматический reconciler и replay/admin UI не добавлены. При утрате broker volume/outbox исходная доставка не восстанавливается автоматически.

DB/filesystem не имеют общей транзакции. SIGKILL между подготовкой PNG и commit может оставить unreferenced PNG или .staging file. Runner считает unreferenced PNG и проверяет существование/тип всех DB-linked images; автоматический orphan sweeper/удаление файлов не добавлены. Post-commit cleanup detail также имеет отдельное окно записи. Это не дубликат товара/счётчика; физическая очистка после произвольной аварии не гарантируется.

Рабочие контейнеры на8080 автоматически не пересобирались/не мигрировались. Angular API/Angular и последующие задачи не выполнялись.
