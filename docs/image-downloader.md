# Загрузчик изображений

`App\Image\ImageDownloader::download(string $url): ImageDownloadResult` возвращает
либо `image: PreparedImage(url, path)` и `warning: null`, либо `image: null` и
типизированное `ImageWarning(url, ImageWarningCode, message, severity=warning)`.
Исходный URL сохраняется даже после redirect. Сообщения фиксированы, без внутренних
путей, cURL errors и stack traces. Код доступен как `warning->code->value`.

Пример вызова из сервиса строки:

```php
$downloader = new App\Image\ImageDownloader(App\Image\ImageStorage::fromEnvironment());
$result = $downloader->download($url);
// $result->image передаётся ProductWriter только при успехе.
// Привязку warning к строке выполняет RowImportService (импорт строки).
```

загрузка изображений не использует Repository/EntityManager, не изменяет товары и не запускает импорт.
Сервис не выполняет повторные попытки скачивания. Ошибка одного URL возвращает warning,
следующий вызов может успешно загрузить другое изображение.

## Проверка адреса и HTTP

- Только HTTP/HTTPS; URL до 2048 байт, без credentials, fragment, пробелов, управляющих
  символов и backslash. Обычные ASCII DNS-имена либо стандартные IP-литералы;
  неоднозначные числовые адреса, короткие локальные имена и нестандартные порты
  отклоняются. В production разрешены порты 80/443.
- Проверяются все IPv4/IPv6 ответы DNS. Loopback, private, link-local, CGNAT, multicast,
  reserved/documentation, IPv4-mapped/transition IPv6 и известный служебный Azure IP
  блокируются. IPv6 разрешён только из глобального 2000::/3 с исключениями специальных
  диапазонов. Смешанный public/private ответ целиком отклоняется.
- DNS выполняется отдельным PHP CLI-процессом с ограниченным временем чтения и
  принудительной остановкой в finally. Команда передаётся массивом, без shell и
  интерполяции hostname в код. Docker/Linux должен предоставлять PHP CLI и proc_open.
- Для DNS-имени выбранный проверенный IP закрепляется через `CURLOPT_RESOLVE`;
  проверенные числовые IP-литералы используются напрямую без повторного DNS. URL-host сохраняется
  для Host/SNI/TLS. Proxy и netrc отключены, проверка сертификата и hostname включена.
  После ответа дополнительно сверяется фактический peer IP. Автоматического fallback
  на другой адрес нет: неудачное соединение возвращает warning.
- Автоматические redirects выключены. Для 301/302/303/307/308 следующий абсолютный URL
  вычисляет libcurl, после чего заново выполняются проверки URL/DNS/IP. Циклы и лимит
  hops отклоняются. Разрешение DNS и все HTTP hops используют общий сетевой deadline.
- Ограничены connect time, фактические байты тела (включая chunked и отсутствие
  Content-Length), заявленная длина и общий объём заголовков. Сжатые HTTP-ответы
  отклоняются; серверу отправляется `Accept-Encoding: identity`.

Pinning и вычисление redirect опираются на официальные API:
[CURLOPT_RESOLVE](https://curl.se/libcurl/c/CURLOPT_RESOLVE.html),
[CURLINFO_REDIRECT_URL](https://curl.se/libcurl/c/CURLINFO_REDIRECT_URL.html).

## Содержимое и хранение

Сопоставляются HTTP Content-Type, Fileinfo MIME и тип/размеры из изображения; затем
GD обязан реально декодировать файл. Одних размеров недостаточно для проверки
содержимого ([PHP getimagesize](https://www.php.net/getimagesize),
[imagecreatefromstring](https://www.php.net/manual/en/function.imagecreatefromstring.php)).
До декодирования проверяются число пикселей и длина стороны.

Текущий Docker-образ проверен для PNG/JPEG/GIF. WebP принимается только если доступен
соответствующий GD decoder; в текущей сборке его отсутствие даёт
`IMAGE_INVALID_CONTENT`. SVG и прочие неподдерживаемые типы отклоняются. Принятый файл
перекодируется в статический PNG: анимация GIF и исходные metadata не сохраняются.
Добавленные скрипты/исходное расширение не переносятся в готовый файл.

`ImageStorage` требует существующий абсолютный канонический IMAGE_DIR, не symlink.
Промежуточные файлы находятся в `.staging` (каталог 0700), при ошибке и успехе удаляются
в finally. Готовый файл имеет имя `32 hex chars.png`, права 0644, публичный path
`/images/<name>.png`; URL не участвует в имени. Лимит байтов применяется также к
результату перекодирования. Hard link внутри одной файловой системы атомарно
публикует полный файл без перезаписи существующего имени. Нужна Linux-файловая система
с поддержкой hard links, как у текущего Docker volume.

## Конфигурация

Переменные описаны в [.env.example](../.env.example) и передаются общим environment
app/worker в [compose.yaml](../compose.yaml). Некорректные значения отклоняются.
IMAGE_DIR уже задан инфраструктурой; сервис сам не создаёт корневой каталог.

| Переменная | Значение по умолчанию |
|---|---|
| IMAGE_MAX_BYTES | 5242880 (5 MiB на ответ и готовый PNG) |
| IMAGE_CONNECT_TIMEOUT_MS | 3000 |
| IMAGE_TOTAL_TIMEOUT_MS | 10000 (DNS + все HTTP hops; декодирование/диск ограничены размером) |
| IMAGE_MAX_REDIRECTS | 3, допустимо 0–10 |
| IMAGE_MAX_PIXELS | 12000000 |
| IMAGE_MAX_DIMENSION | 10000 |
| IMAGE_MAX_HEADER_BYTES | 16384 |

Безопасность адресов не отключается переменной окружения. Только явный вызов
`ImageUrlPolicy::forTestServer('http://fixture.test:<port>')` при `APP_ENV=test`
разрешает ровно этот origin и ровно IP 127.0.0.1 (также поддерживается явный HTTPS origin). Другой origin/redirect проверяется
обычной политикой; стандартный конструктор остаётся строгим даже в test. В production
создание исключения бросает LogicException. TLS-проверка остаётся включённой и в test.

Коды предупреждений: IMAGE_URL_REJECTED, IMAGE_ADDRESS_REJECTED, IMAGE_DNS_FAILED,
IMAGE_HTTP_ERROR, IMAGE_TIMEOUT, IMAGE_TOO_LARGE, IMAGE_INVALID_CONTENT,
IMAGE_REDIRECT_REJECTED, IMAGE_DOWNLOAD_FAILED, IMAGE_STORAGE_FAILED.

## Самостоятельная проверка

Из PowerShell при запущенном Docker Desktop Linux Engine:

```powershell
python scripts/verify.py check
python scripts/verify.py test
```

Ожидаются код 0, PASS и `OK (348 tests, ... assertions)` (число assertions зависит от
ограниченного ожидания процессов запись товаров). Проверки изолированы от working DB/images,
PHP/Composer/Make на хосте не нужны. Доступ к registry нужен при отсутствии кеша.

83 новых тестовых случая загрузка изображений используют управляемый loopback HTTP/TLS-сервер в
тестовом контейнере, временный собственный image directory и искусственные файлы.
Проверены PNG/JPEG/GIF/redirect, неизменный исходный URL, pinning имени без DNS-записи,
отключение proxy, 404, timeout, byte/header/pixel/dimension limits, fake MIME/HTML/
повреждённый PNG, удаление добавленного script payload, redirect loop/count/private/
scheme/credentials/другой origin, DNS/mixed addresses, ошибка хранилища, symlink,
сохранность прежнего файла, очистка staging и недоверенный TLS-сертификат. DNS-процесс
также проверен с ограничением времени. Внешние catalog.collant.ru и исходный XLSX
не используются. Большие предельные файлы и успешное TLS-соединение с внешним CA
нагрузочно не профилировались.
