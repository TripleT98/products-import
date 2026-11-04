const { test, expect } = require('@playwright/test');
const origin = process.env.AUTH_TEST_ORIGIN;
test.use({ channel: 'msedge' });

test('45 real fixtures: pages, filters, reload, card/back, error/retry, empty and stale-request cancellation', async ({ page }) => {
  test.setTimeout(90000);
  const listResponse = predicate => page.waitForResponse(r => {
    const url = new URL(r.url());
    return url.pathname === '/api/products' && r.status() === 200 && predicate(url.searchParams);
  });
  const rows = page.locator('tr.product-row');
  await page.goto(origin + '/products');
  await page.getByLabel('Имя пользователя', { exact: true }).fill('worker-fixture');
  await page.getByLabel('Пароль', { exact: true }).fill('worker-test-password');
  const first = listResponse(p => p.get('page') === '1');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  expect((await (await first).json()).pagination).toEqual({ page: 1, limit: 20, total: 45, totalPages: 3 });
  await expect(rows).toHaveCount(20);
  await expect(page.getByText('Найдено: 45', { exact: true })).toBeVisible();
  const second = listResponse(p => p.get('page') === '2');
  await page.getByRole('button', { name: 'Следующая страница', exact: true }).click();
  const secondBody = await (await second).json();
  expect(secondBody.items[0].externalCode).toBe('LIST-0021');
  await expect(rows.first()).toContainText('LIST-0021');
  await expect(rows).toHaveCount(20);
  const third = listResponse(p => p.get('page') === '3');
  await page.getByRole('button', { name: 'Следующая страница', exact: true }).click();
  expect((await (await third).json()).items).toHaveLength(5);
  await expect(rows).toHaveCount(5);
  const filtered = listResponse(p => p.get('name') === 'чай' && p.get('page') === '1');
  await page.getByLabel('Название', { exact: true }).fill('чай');
  expect((await (await filtered).json()).pagination.total).toBe(23);
  await expect(page).toHaveURL(/page=1/);
  const ranged = listResponse(p => p.get('price_min') === '1000' && p.get('price_max') === '2000');
  await page.getByLabel('Цена от', { exact: true }).fill('1000');
  await page.getByLabel('Цена до', { exact: true }).fill('2000');
  const rangeBody = await (await ranged).json();
  expect(rangeBody.pagination.total).toBe(5);
  await expect(rows).toHaveCount(5);
  expect(rangeBody.items.every(p => p.name.startsWith('Чай') && Number(p.price) >= 1000 && Number(p.price) <= 2000)).toBe(true);
  const url = page.url();
  const reload = listResponse(p => p.get('name') === 'чай' && p.get('price_min') === '1000');
  await page.reload(); await reload;
  await expect(page.getByLabel('Название', { exact: true })).toHaveValue('чай');
  await expect(page.getByLabel('Цена до', { exact: true })).toHaveValue('2000');
  await expect(rows).toHaveCount(5);
  await expect(rows.first().getByRole('link')).toHaveAttribute('href', /\/products\/11\?/);
  await rows.first().click();
  await expect(page.getByRole('heading', { name: 'Карточка товара' })).toBeVisible();
  await page.goBack();
  await expect(page).toHaveURL(url);
  await expect(rows).toHaveCount(5);
  await rows.first().focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Карточка товара' })).toBeVisible();
  await page.goBack();
  await expect(rows).toHaveCount(5);
  await page.screenshot({ path: 'products-list.png', fullPage: true });

  await page.route('**/api/products?**', async route => {
    await route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":{"code":"TEST_FAILURE","message":"private diagnostic","details":[]}}' });
  }, { times: 1 });
  await page.getByRole('button', { name: 'Сбросить фильтры' }).click();
  await expect(page.getByRole('button', { name: 'Повторить загрузку' })).toBeVisible();
  await expect(page.locator('body')).not.toContainText('private diagnostic');
  const retry = listResponse(p => p.get('page') === '1' && !p.has('name'));
  await page.getByRole('button', { name: 'Повторить загрузку' }).click(); await retry;
  await expect(rows).toHaveCount(20);
  const empty = listResponse(p => p.get('name') === 'нет-такого');
  await page.getByLabel('Название', { exact: true }).fill('нет-такого');
  expect((await (await empty).json()).pagination.total).toBe(0);
  await expect(page.getByText('Товары не найдены.', { exact: false })).toBeVisible();

  // Delay a REAL server response for the old query; the newer query must win.
  let signalOld;
  const oldSeen = new Promise(resolve => { signalOld = resolve; });
  await page.route('**/api/products?**', async route => {
    const p = new URL(route.request().url()).searchParams;
    if (p.get('name') !== 'чай') return route.continue();
    signalOld();
    const response = await route.fetch();
    await new Promise(resolve => setTimeout(resolve, 1000));
    try { await route.fulfill({ response }); } catch { /* Angular unsubscribed/aborted the old request. */ }
  });
  await page.getByLabel('Название', { exact: true }).fill('чай'); await oldSeen;
  await expect(page.getByRole('progressbar', { name: 'Загрузка товаров' })).toBeVisible();
  const newest = listResponse(p => p.get('name') === 'кофе');
  await page.getByLabel('Название', { exact: true }).fill('кофе');
  expect((await (await newest).json()).pagination.total).toBe(22);
  await expect(rows).toHaveCount(20);
  await expect(rows.first()).toContainText('Кофе');
  await expect(rows).not.toContainText(['Чай']);
  await page.waitForTimeout(1100); // Bounded controlled-response test window, not application readiness.
  await expect(rows.first()).toContainText('Кофе');
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.getByLabel('Название', { exact: true })).toBeVisible();
  await page.screenshot({ path: 'products-list-mobile.png', fullPage: true });
});
