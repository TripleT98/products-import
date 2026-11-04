# Чтение структуры XLSX


## Компоненты

WorkbookReader::open(path) возвращает Workbook с sheetName, mapping, dataRowCount, sha256 и rows(): Generator<WorkbookRow>. open сначала проверяет всю структуру и считает непустые строки; затем rows последовательно выдаёт исходные строки с настоящим rowNumber. Между inspect и итерацией проверяется SHA-256: изменённый файл отклоняется. Повторная итерация читает заново, не сохраняет все строки в массив.

PhpSpreadsheet 5.10.0 читает значения/строки/rich text/style через Xlsx и ChunkFilter (250 физических строк по умолчанию); выбран только нужный лист. У старой порции вызывается disconnectWorksheets. XMLReader читает ZIP-части потоково и сохраняет исходную лексему числовых ячеек до преобразования PhpSpreadsheet в float: 9999999999999999.99 остаётся точной строкой. Сохраняется numberFormat, чтобы дальнейшая нормализация могла учитывать представление кода. Метаданные/стили/shared strings PhpSpreadsheet всё ещё читает из книги: это ограниченные ZIP-лимитами данные, а не обещание постоянного расхода памяти независимо от размера. Большие книги требуют отдельного профилирования в следующих этапах.

CellData содержит value (string/bool/null), type (например s/n/f/b/e/null), numberFormat. Даже числовые значения выдаются исходными строками; нет расчётов с float. Формула передаётся с type=f и исходным выражением с =, cached result не используется. Нет вызовов getCalculatedValue, вычисления формул или обработки инструкций из ячеек. WorkbookRow::field(name) возвращает CellData по mapping; отсутствующее описание — null, превращение в пустую строку относится к валидация строк. Полностью пустые/пробельные строки пропускаются, 0/false/формула считаются непустыми. Содержимое игнорируемых колонок не импортируется, но участвует в определении полностью пустой строки.

## Листы и заголовки

Рассматриваются все листы, включая скрытые: ровно один непустой, пустые игнорируются. Лист только с непустыми заголовками допустим и имеет 0 строк данных. Заголовки всегда в строке 1, не ищутся в последующих строках. Несколько непустых или ни одного — INVALID_WORKBOOK.

HeaderMapper убирает крайние Unicode-пробелы/BOM, сохраняет регистр и ищет обязательные заголовки Внешний код/Наименование/Цена: Цена продажи/Закупочная цена. Описание необязательно. Сопоставление по имени, не позиции. Повтор нормализованного непустого заголовка, пропуск обязательного поля, пустой/повторный ключ Доп. поле и формула в заголовке — INVALID_HEADERS. Доп. поле: Размер и Доп. поле Размер дают один ключ Размер. Здесь определяется только ключ/колонка; значения атрибутов и URL не выделяются. Остальные стандартные поля перечисляются в ignored mapping. Пустые заголовки игнорируются.

## Проверка контейнера

XlsxPackage работает с ZipArchive RDONLY, не извлекает архив на диск. Проверяются целостность контейнера, обязательные OOXML-части, дублирующиеся ZIP-имена, опасные пути/шифрование, лимиты. XMLReader использует LIBXML_NONET, LOADDTD=false/SUBST_ENTITIES=false; DTD/ENTITY и malformed XML отклоняются до чтения PhpSpreadsheet. Макросы, внешние книги/изображения не поддерживаются. Ошибки — INVALID_WORKBOOK или WORKBOOK_LIMIT_EXCEEDED, без произвольных сообщений библиотеки в CLI.

| Переменная / WorkbookLimits | По умолчанию |
|---|---|
| XLSX_MAX_FILE_BYTES / maxFileBytes | 10485760 (10 MiB) |
| XLSX_MAX_UNCOMPRESSED_BYTES / maxUncompressedBytes | 104857600 (100 MiB) |
| XLSX_MAX_ZIP_ENTRIES / maxZipEntries | 1000 |
| XLSX_MAX_COMPRESSION_RATIO / maxCompressionRatio | 100 на ZIP-часть |
| XLSX_MAX_DATA_ROWS / maxDataRows | 10000 непустых строк данных |
| XLSX_MAX_ROW_NUMBER / maxRowNumber | 100001 |
| XLSX_CHUNK_ROWS / chunkRows | 250 |

Значения положительные целые; CLI читает их из env, программный reader допускает передачу WorkbookLimits. Они описаны в .env.example и передаются Compose app/worker. Предельная колонка — XFD (16384), ошибочные/повторные cell references и не возрастающие номера строк отклоняются. API upload и его MIME/транспортная проверка не реализованы (загрузка XLSX).

## Самостоятельная проверка

PowerShell из корня проекта:

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-workbook.py --xlsx 'path/to/workbook.xlsx' --sample
```

Ожидается код 0 и PASS. check/test включают Unit/Integration/Smoke, PHPStan level 5/style и frontend regression. Тесты создают только искусственные XLSX в tmp контейнера и удаляют их; private исходник не нужен для обычного check/test и не копируется в Git.

check-workbook.py собирает production image product-import-workbook:t09, подключает указанный файл read-only как /input/workbook.xlsx, запускает --network none с DB_HOST=must-not-connect.invalid и выполняет:

```text
APP_ENV=development php bin/console workbook:inspect /input/workbook.xlsx
```

В подготовленном контейнере CLI доступна только при APP_ENV=development/test; без этого возвращает ненулевой код. Production-mode HTTP endpoint для чтения не создавался. Общий bin/console создаёт ленивую ORM-конфигурацию для других команд, но workbook:inspect ни разу не обращается к БД — подтверждено offline прогоном с недоступным адресом БД.

## Что проверяют тесты чтение XLSX

## Следующий готовый слой валидация строк

Поверх WorkbookRow реализован [чистый поток RowDto/issues](row-validation.md), не меняющий формат raw reader. Необязательный флаг workbook:inspect --validate / check-workbook.py --validate печатает дополнительные агрегаты; прежний режим чтение XLSX сохранён.
