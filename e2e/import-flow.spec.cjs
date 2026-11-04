const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

test('real login → upload → worker → report → list → card, repeat and update', async ({ page }, testInfo) => {
  const api = async resource => page.evaluate(async resource => {
    const session = JSON.parse(sessionStorage.getItem('product-import.auth'));
    const response = await fetch('/api/' + resource, { headers: { Authorization: 'Bearer ' + session.accessToken } });
    return { status: response.status, body: await response.json() };
  }, resource);
  const upload = async filename => {
    await page.goto('/import');
    await page.getByLabel('Файл XLSX').setInputFiles(path.join(process.env.E2E_WORKBOOK_DIR, filename));
    const accepted = page.waitForResponse(r => new URL(r.url()).pathname === '/api/imports' && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Начать импорт' }).click();
    const response = await accepted; expect(response.status()).toBe(202);
    const job = await response.json(); expect(job.status).toBe('queued');
    await expect(page).toHaveURL(new RegExp('/import/' + job.jobId + '$'));
    return job.jobId;
  };
  const finished = async (status, counters) => {
    await expect(page.getByRole('status', { name: status, exact: true })).toBeVisible({ timeout: 45000 });
    for (const [key, value] of Object.entries(counters)) await expect(page.getByTestId(key)).toHaveText(String(value));
  };
  const card = page.locator('app-product-detail-page > mat-card');
  let firstId;
  let originalImage;
  let updateJob;

  await test.step('guard, real incorrect and successful login', async () => {
    await page.goto('/import');
    await expect(page.getByRole('heading', { name: 'Вход', exact: true })).toBeVisible();
    expect(new URL(page.url()).searchParams.get('returnUrl')).toBe('/import');
    await page.getByLabel('Имя пользователя', { exact: true }).fill('worker-fixture');
    await page.getByLabel('Пароль', { exact: true }).fill('wrong-password');
    const denied = page.waitForResponse(r => r.url().endsWith('/api/auth/login'));
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    expect((await denied).status()).toBe(401);
    await expect(page.getByRole('alert')).toHaveText('Неверное имя пользователя или пароль.');
    await page.getByLabel('Пароль', { exact: true }).fill('worker-test-password');
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    await expect(page).toHaveURL(/\/import$/);
  });

  await test.step('25 actual rows with worker stopped, then real processing and completed result', async () => {
    const job = await upload('e2e-initial.xlsx');
    await expect(page.getByRole('status', { name: 'В очереди', exact: true })).toBeVisible();
    const before = await api('products?page=1&limit=20');
    expect(before.status).toBe(200); expect(before.body.pagination.total).toBe(0); expect(before.body.items).toEqual([]);
    const args = JSON.parse(process.env.E2E_COMPOSE_ARGS);
    if (!args.includes('product-import-e2e-check')) throw new Error('Refusing to start a worker outside the isolated e2e project');
    execFileSync(process.env.E2E_DOCKER, args.concat(['up', '-d', '--no-deps', 'worker']), { timeout: 60000, stdio: 'pipe' });
    await expect(page.getByRole('status', { name: 'Обработка', exact: true })).toBeVisible({ timeout: 15000 });
    await finished('Завершено', { totalRows: 25, processedRows: 25, createdProducts: 25, updatedProducts: 0, failedRows: 0, warningCount: 0 });
    await page.reload();
    await finished('Завершено', { processedRows: 25, createdProducts: 25 });
    await expect(page.getByText('Ошибок и предупреждений нет.', { exact: true })).toBeVisible();
    const result = await api('imports/' + job + '/issues?page=1&limit=50');
    expect(result.status).toBe(200); expect(result.body.items).toEqual([]);
  });

  await test.step('real server pages 20+5, filter/range and persisted product with local PNG', async () => {
    await page.getByRole('link', { name: 'К товарам', exact: true }).click();
    await expect(page.locator('tr.product-row')).toHaveCount(20);
    await expect(page.getByText('Найдено: 25', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Следующая страница', exact: true }).click();
    await expect(page.locator('tr.product-row')).toHaveCount(5);
    await expect(page.locator('tr.product-row').first()).toContainText('E2E-0021');
    await page.reload(); await expect(page.locator('tr.product-row')).toHaveCount(5);
    await page.getByLabel('Название', { exact: true }).fill('E2E товар');
    await page.getByLabel('Цена от', { exact: true }).fill('101');
    await page.getByLabel('Цена до', { exact: true }).fill('110');
    await expect(page.getByText('Найдено: 10', { exact: true })).toBeVisible();
    await expect(page.locator('tr.product-row')).toHaveCount(10);
    await page.reload(); await expect(page.locator('tr.product-row')).toHaveCount(10);
    await expect(page.getByLabel('Цена до', { exact: true })).toHaveValue('110');
    await page.getByRole('link', { name: 'E2E товар 0001', exact: true }).click();
    await expect(page).toHaveURL(/\/products\/\d+\?/);
    firstId = new URL(page.url()).pathname.split('/').pop();
    await expect(card.getByRole('heading', { name: 'E2E товар 0001', exact: true })).toBeVisible();
    await expect(card.getByText('101.00', { exact: true })).toBeVisible();
    await expect(card.getByText('25.74', { exact: true })).toBeVisible();
    await expect(card.getByText('0', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Отчёт выбранного импорта', exact: true })).toBeVisible();
    await expect(card.locator('img')).toHaveCount(1);
    const img = card.locator('img');
    await expect.poll(() => img.evaluate(el => el.complete && el.naturalWidth > 0)).toBe(true);
    originalImage = await img.getAttribute('src'); expect(originalImage).toMatch(/^\/images\/.+\.png$/);
    await page.reload(); await expect(card.locator('img')).toHaveAttribute('src', originalImage);
    await page.getByRole('link', { name: 'К списку товаров', exact: true }).click();
    await expect(page.getByLabel('Название', { exact: true })).toHaveValue('E2E товар');
    await expect(page.getByLabel('Цена до', { exact: true })).toHaveValue('110');
    await expect(page.locator('tr.product-row')).toHaveCount(10);
  });

  await test.step('repeat the same workbook: update 25, create zero, stable IDs and image sets', async () => {
    await upload('e2e-initial.xlsx');
    await finished('Завершено', { totalRows: 25, processedRows: 25, createdProducts: 0, updatedProducts: 25, failedRows: 0, warningCount: 0 });
    await page.reload(); await finished('Завершено', { createdProducts: 0, updatedProducts: 25 });
    const list = await api('products?page=1&limit=100');
    expect(list.body.pagination.total).toBe(25);
    expect(new Set(list.body.items.map(p => p.externalCode)).size).toBe(25);
    const product = list.body.items.find(p => p.externalCode === 'E2E-0001'); expect(String(product.id)).toBe(firstId);
    const detail = await api('products/' + firstId);
    expect(detail.body.attributes).toHaveLength(3); expect(detail.body.images).toHaveLength(1);
    expect(detail.body.attributes.map(a => [a.key, a.value]).sort()).toEqual([
      ['Размер', '0'], ['Ссылки на фото', 'http://fixture.test:18080/slow'], ['Цвет', 'Синий'],
    ]);
    expect(detail.body.images[0].url).toBe('http://fixture.test:18080/slow');
    expect(detail.body.images[0].path).toMatch(/^\/images\/.+\.png$/);
  });

  await test.step('update + new row + invalid row + real image warning, persisted report', async () => {
    updateJob = await upload('e2e-update.xlsx');
    await finished('Завершено с замечаниями', { totalRows: 3, processedRows: 3, createdProducts: 1, updatedProducts: 1, failedRows: 1, warningCount: 1 });
    const issues = page.locator('table[aria-label="Ошибки и предупреждения импорта"] tbody tr');
    await expect(issues).toHaveCount(2);
    await expect(issues.filter({ hasText: 'Предупреждение' })).toContainText('IMAGE_HTTP_ERROR');
    await expect(issues.filter({ hasText: 'Ошибка' })).toContainText('INVALID_PRICE');
    await page.reload(); await expect(issues).toHaveCount(2);
    const report = await api('imports/' + updateJob + '/issues?page=1&limit=50');
    expect(report.body.items).toEqual(expect.arrayContaining([
      expect.objectContaining({ severity: 'warning', code: 'IMAGE_HTTP_ERROR', rowNumber: 2, externalCode: 'E2E-0001', field: 'images' }),
      expect.objectContaining({ severity: 'error', code: 'INVALID_PRICE', rowNumber: 4, externalCode: 'E2E-0027', field: 'price' }),
    ]));
    const list = await api('products?page=1&limit=100');
    expect(list.body.pagination.total).toBe(26); expect(list.body.items.some(p => p.externalCode === 'E2E-0027')).toBe(false);
    const detail = await api('products/' + firstId);
    expect(detail.body).toMatchObject({ name: 'E2E обновлён', price: '200.00', purchasePrice: '150.00', discount: '25.00' });
    expect(detail.body.attributes.map(a => [a.key, a.value]).sort()).toEqual([
      ['Новый', 'Да'], ['Размер', 'L'], ['Ссылки на фото', 'http://fixture.test:18080/jpeg,http://fixture.test:18080/404'],
    ]);
    expect(detail.body.images).toHaveLength(1); expect(detail.body.images[0].url).toBe('http://fixture.test:18080/jpeg');
    expect(detail.body.images[0].path).not.toBe(originalImage);
  });

  await test.step('updated card via filtered catalog, local image and import context after reload, logout/guard', async () => {
    await page.getByRole('link', { name: 'К товарам', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/products' && url.searchParams.get('importJobId') === updateJob);
    expect(new URL(page.url()).searchParams.get('importJobId')).toBe(updateJob);
    await expect(page.getByText('Найдено: 26', { exact: true })).toBeVisible();
    await page.getByLabel('Название', { exact: true }).fill('обновлён');
    await expect(page.locator('tr.product-row')).toHaveCount(1);
    await page.getByRole('link', { name: 'E2E обновлён', exact: true }).click();
    await expect(page).toHaveURL(/\/products\/\d+\?/);
    expect(new URL(page.url()).searchParams.get('importJobId')).toBe(updateJob);
    await page.reload();
    await expect(card.getByRole('heading', { name: 'E2E обновлён', exact: true })).toBeVisible();
    await expect(card.getByText('200.00', { exact: true })).toBeVisible();
    await expect(card.getByText('Данные после обновления', { exact: true })).toBeVisible();
    await expect(card.getByText('L', { exact: true })).toBeVisible();
    await expect(card.getByText('Синий', { exact: true })).toHaveCount(0);
    await expect.poll(() => card.locator('img').evaluate(el => el.complete && el.naturalWidth > 0)).toBe(true);
    expect(await card.locator('img').getAttribute('src')).toMatch(/^\/images\/.+\.png$/);
    await expect(page.locator('app-import-report-widget tbody tr')).toHaveCount(2);
    await finished('Завершено с замечаниями', { processedRows: 3, createdProducts: 1, updatedProducts: 1, failedRows: 1, warningCount: 1 });
    await page.screenshot({ path: testInfo.outputPath('updated-card.png'), fullPage: true });
    await page.getByRole('link', { name: 'К списку товаров', exact: true }).click();
    await expect(page.getByLabel('Название', { exact: true })).toHaveValue('обновлён');
    await expect(page.locator('tr.product-row')).toHaveCount(1);
    await page.getByRole('button', { name: 'Выйти', exact: true }).click();
    await page.goto('/products/' + firstId + '?importJobId=' + updateJob);
    await expect(page.getByRole('heading', { name: 'Вход', exact: true })).toBeVisible();
  });
});
