# NgRx списка товаров

NgRx Store/Effects управляют серверной загрузкой списка товаров. Интерфейс списка описан в [products-list](products-list.md).

## Проверить

PowerShell из корня проекта:

```powershell
python scripts/verify.py check
python scripts/verify.py test
python scripts/check-auth-ui.py
```

Ожидаются exit0/PASS. Check выполняет backend tests/static/schema/fixtures, frontend lint/strict app+spec typecheck/tests/production build. Test повторяет наборы тестов. Browser auth regression требует Node/npm и установленный Edge, запускает отдельный production app/test DB и проверяет, что новая NgRx registration не нарушает вход. Рабочие8080/.env/данные не используются и автоматически не обновляются.

products.spec.ts содержит reducer/selector unit cases и integration tests с настоящими Store/Effects/providers/ProductService и HttpTestingController: success/exact filters, failure400/401/500/503→recovery, synchronous failure→recovery, реальная отмена старого HTTP запроса и только новый результат. Не mock-ится сам effect и не подменяется ProductService для основных wire tests; spy доказывает вызов service. Тест отмены проверяет old.cancelled и невозможность flush отменённого запроса. Runtime strict checks включены и в тесте.

Компонент списка не менялся, HttpClient в нём нет. Визуального списка/новых пользовательских действий в этой задаче нет; проверяемый результат — готовое состояние и effects для список товаров. Нет полного browser→NgRx→таблица сценария до реализации список товаров. Фактические команды/числа/ограничения, локальный отчёт проверки.
