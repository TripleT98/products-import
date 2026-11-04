# Страница списка товаров

Полноценная карточка и явная кнопка возврата добавлены следующим шагом карточка товара: [product-detail.md](product-detail.md). Ниже сохранены границы список товаров.


## Экран и данные

`/products` содержит Material-таблицу (код/название/цена/скидка), поля name/price_min/price_max, сброс фильтров, серверную пагинацию с размерами10/20/50/100 и общим COUNT. Тексты/decimal strings выводятся без HTML и Number-округления. Строка открывается мышью/Enter, название — обычная ссылка с поддержкой клавиатуры. Ссылка сохраняет параметры списка и существующий importJobId; widget импорта не добавлен. Пока карточка остаётся placeholder Angular API, возврат браузером назад восстанавливает список/параметры.

ProductListPage использует store.select и async pipe, без HttpClient/subscribe()/client-side slice/MatTableDataSource paginator. Таблица получает ровно items ответа. Loading/progress, безопасная ошибка с retry, empty state и далёкая пустая страница представлены отдельно. Retry очищает прежний общий notification и повторяет текущий query через NgRx.

ProductListController — scoped service для формы/маршрута: единственная managed subscription связывает queryParamMap и valueChanges, уничтожается с компонентом. API вызывается исключительно прежним NgRx effect→ProductService. Route является источником запроса: page/limit/name/price_min/price_max сохраняются в URL; refresh/back/forward восстанавливают форму и отправляют отдельный API request. Смена только importJobId не повторяет одинаковый запрос.

Изменение полей ждёт300ms, сбрасывает page=1, trim пустого name удаляет фильтр; Enter применяет немедленно. Изменение размера страницы также сбрасывает page=1. Навигация отменяет ещё не применённые debounced edits, чтобы старый timer не перезаписал URL после Back. Новый load использует switchMap NgRx для отмены старого HTTP. Цена валидируется как неотрицательная decimal string с максимум16 целыми/2 дробными знаками; границы сравниваются точно строками, включая0 и большие значения. Ошибочные/repeated/page-out-of-range параметры URL дают явное сообщение, без молчаливого clamp и без отправки неверного запроса. Backend сохраняет окончательную валидацию.

Пагинатор переведён на русский. На узком экране фильтры переносятся, таблица прокручивается горизонтально внутри карточки. Используются системные font tokens Material без внешних fonts/CDN. Desktop и mobile390px screenshots проверены визуально.

## Самостоятельная проверка

PowerShell из корня, Docker Desktop Linux Engine/Python:

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-products-ui.py
```

Каждая команда: exit0/PASS. Check: Composer/PHPStan/style/schema/fixtures/backend tests плюс frontend lint/strict app+spec typecheck/71 tests/production build. Test повторяет tests. Есть15 новых unit cases route/decimal/bounds/repeated query; прежние NgRx cancellation/recovery tests сохранены. Обновлён shell test для настоящего списка/Store и явного completion HTTP testing requests.

Browser runner (`make products-ui-check` при установленном Make) требует также host Node/npm/Microsoft Edge. Он повторно использует ignored Playwright1.56.1 tooling вход, при первом запуске устанавливает его только в tmp. Создаёт отдельный product-import-list-ui-check: production nginx/FPM, disposable PG/RabbitMQ/fixture owner, свои volumes/networks; только nginx на случайном127.0.0.1 port. Main .env/БД/контейнеры не меняются, worker не запускается, cleanup собственных ресурсов выполняется finally.

`backend/tests/Support/list-fixture.php` в пустой isolated DB создаёт ровно45 синтетических товаров LIST-0001…0045, цену100…4500, названия Чай(нечётные)/Кофе(чётные). Это отдельная test fixture, обычный CatalogFixtures48 не изменён. Browser реально входит как worker-fixture/worker-test-password и проверяет:

1. Network ответы page1/2/3:20/20/5 items, COUNT45, totalPages3; вторая страница начинается LIST-0021.
2. name=чай сбрасывает page1, API COUNT23; price1000…2000 даёт COUNT5, ровно5 строк, каждый товар соответствует фильтрам.
3. Refresh сохраняет URL/форму/запрос; ссылки содержат query. Click строки и Enter открывают временную карточку; Back возвращает фильтрованный список.
4. Управляемый HTTP500 показывает безопасную ошибку/retry без raw body, retry снова получает настоящий API ответ; unknown name →COUNT0/empty state.
5. Реальный server response старого name=чай искусственно задержан, новый name=кофе даёт COUNT22; loading виден и после controlled delay остаются только новые данные. Это browser network interception для задержки/500, не подмена успешной таблицы: все успешные payloads получены настоящим backend/PG.
6. Mobile390px, видимость формы и screenshots.

Отчёт tmp/verification-list-ui.json; screenshots tmp/t20-browser-tooling/products-list.png и products-list-mobile.png (synthetic test data, без токенов). локальный отчёт проверки, фактический статус.

## Ограничения

карточка товара полноценная карточка, прогресс импорта/отчёт импорта upload/polling/report/widget не начаты. Проверено возвращение через browser Back; кнопка возврата в карточке относится к карточка товара. importJobId только сохраняется в ссылках, не утверждает происхождение товаров. Store не persisted между reload: параметры восстанавливаются из URL, данные повторно читаются с сервера. Нет экспорта/write CRUD/постраничной client-side сортировки.

Browser проверен на Windows/Edge, требует Node/npm и свободный случайный loopback port. Большая нагрузка/тысячи страниц не профилировались. Прежние npm audit issues не исправлялись; зависимости/lockfiles/API не менялись. Исходный XLSX/внешние изображения не использовались. UI построен на [Angular async pipe](https://angular.dev/guide/templates/pipes) и [Material paginator](https://material.angular.dev/components/paginator).
