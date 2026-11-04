# Отчёт выбранного импорта


На `/import/:jobId` и `/products/:id?importJobId=UUID` используется один ImportReportWidget: статус, прогресс, итоги, fatal summary, pause/retry и таблица ошибок/предупреждений. Подпись «Отчёт выбранного импорта» и пояснение относятся ко всей задаче; контекст не доказывает принадлежность текущего товара этому импорту. Ошибка задачи не скрывает карточку товара. Без непустого importJobId карточка не создаёт виджет и не обращается к imports API.

«К товарам» передаёт importJobId в query списка; существующий список сохраняет ID и фильтры в ссылках карточек/возврата. На карточке виджет сохраняет также page/limit/name/price_min/price_max при переходе в каталог. После refresh статус и отчёт заново читаются с сервера, File не нужен. Размер/страница отчёта при refresh сбрасываются на 1/50; контекст списка остаётся в URL.

ImportIssuesReport использует прежний GET issues с server page/limit, без локального разбиения. Размеры 10/20/50/100. Таблица: уровень (ошибка/предупреждение), номер строки, внешний код, поле, код и сообщение; nullable metadata → «—». Значения выводятся interpolation, без innerHTML; длинные строки переносятся, таблица прокручивается внутри экрана. Ошибка отчёта имеет безопасное сообщение и ручной retry, независимо от данных товара и статуса.

При активном импорте status polling остаётся response-relative2s без перекрытий. Изменение статуса/failedRows/warningCount обновляет отчёт текущей страницы. У таблицы нет отдельного таймера. Неизменные counters не создают повторный GET issues; terminal завершает автоматическое обновление. Смена ID/страницы/уход отменяет старые requests. Недоступная/чужая задача одинаково показывает безопасную404; malformed UUID не отправляет HTTP. При ошибке status отчёт не загружается до успешного retry.

## Автоматическая проверка

PowerShell, корень `корень репозитория`, Docker Desktop Linux Engine:

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-report-ui.py
python scripts/check-import-ui.py
```

Ожидается код0/PASS. check: Composer strict, PHPStan5/style, миграции/schema, PHPUnit, fixtures дважды, frontend lint/strict typecheck/tests/build. test повторяет backend/frontend suites. Не запускать check/test одновременно: они используют один тестовый project. Browser runners используют отдельные disposable projects; рабочие8080/данные не меняют. Требуются Python/Docker; browser дополнительно Node/npm/установленный Microsoft Edge. Playwright1.56.1 хранится в ignored tmp, app dependencies не меняются. При отсутствии tooling runner устанавливает только его в tmp.

`check-report-ui.py` создаёт20 synthetic XLSX rows (8 valid/12 invalid price/1 image warning),45 независимых товаров и чужой job. Production nginx/FPM/worker, реальные PG/Rabbit/controlled image HTTP. Проверяет queued→processing→completed_with_errors,13 issues, server10+3 pages, result→catalog→unrelated card→back, refresh на result/card, отсутствие polling без ID/после terminal, реальные missing/foreign404 с сохранением товара, mobile390px. Успешные responses не подменяются. Отчёт `tmp/verification-report-ui.json`, screenshots `tmp/t20-browser-tooling/report-card.png`, `report-mobile.png`. прогресс импорта runner повторно проверяет upload/poll lifecycle; его429/503/delay контролируются тестом.

## Ручная проверка

На развёрнутой актуальной версии войти, открыть `/import`, загрузить XLSX с неверной ценой одной строки и недоступным фото другой. Дождаться завершения: failedRows и warningCount ненулевые, таблица содержит уровни, строки, коды и сообщения. Сменить размер/страницу при достаточном количестве записей; обновить экран — отчёт остаётся доступен с сервера. Нажать «К товарам»: адрес содержит importJobId. Открыть любой товар: его данные и отдельный «Отчёт выбранного импорта» видны вместе. Возврат сохраняет query списка.

Оригинальный XLSX не меняется и в тесты не публикуется. Большие отчёты/нагрузка не профилировались; terminal polling остановлен, не предусмотрено внешнее live-edit отчёта. Постоянного product→job отношения нет. e2e end-to-end suite, CI и последующие задачи не входят в отчёт импорта.
