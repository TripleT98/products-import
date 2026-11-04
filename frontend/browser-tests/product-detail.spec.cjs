const { test, expect } = require('@playwright/test');
const origin = process.env.AUTH_TEST_ORIGIN;
test.use({ channel: 'msedge' });

test('real product card: fields, attributes, safe long text, local gallery, fallbacks, errors and filtered return', async ({ page }) => {
  test.setTimeout(90000);
  const external = [];
  page.on('request', r => { if (new URL(r.url()).hostname === 'external.invalid') external.push(r.url()); });
  await page.goto(origin + '/products?page=2&limit=10&name=чай');
  await page.getByLabel('Имя пользователя', { exact: true }).fill('worker-fixture');
  await page.getByLabel('Пароль', { exact: true }).fill('worker-test-password');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page.locator('tr.product-row')).toHaveCount(10);
  // Direct URL with a filtered-list context, restored through the explicit back link.
  await page.goto(origin + '/products/11?page=2&limit=10&name=чай&importJobId=context');
  await expect(page.locator('app-product-detail-page > mat-card').getByRole('heading', { level: 2 })).toContainText('Чай 11');
  await expect(page.getByText('LIST-0011', { exact: true })).toBeVisible();
  await expect(page.getByText('1100.00', { exact: true })).toBeVisible();
  await expect(page.getByText('825.00', { exact: true })).toBeVisible();
  await expect(page.getByText('0', { exact: true })).toBeVisible();
  await expect(page.locator('.description')).toContainText('<img src=x onerror=');
  expect(await page.evaluate(() => window.__detailXss === true)).toBe(false);
  await expect(page.locator('.gallery img')).toHaveCount(2);
  for (const image of await page.locator('.gallery img').all()) {
    expect(await image.evaluate(el => el.complete && el.naturalWidth === 480)).toBe(true);
    expect(await image.getAttribute('src')).toMatch(/^\/images\//);
  }
  await expect(page.getByRole('img', { name: 'Изображение недоступно', exact: true })).toBeVisible();
  expect(external).toEqual([]);
  await page.screenshot({ path: 'product-detail.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.getByRole('link', { name: 'К списку товаров' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: 'product-detail-mobile.png', fullPage: true });
  await page.getByRole('link', { name: 'К списку товаров' }).click();
  await expect(page).toHaveURL(/\/products\?page=2&limit=10&name=/);
  expect(new URL(page.url()).searchParams.get('importJobId')).toBe('context');
  await expect(page.getByLabel('Название', { exact: true })).toHaveValue('чай');
  await expect(page.locator('tr.product-row')).toHaveCount(10);
  await page.locator('tr.product-row').first().getByRole('link').click();
  await expect(page.locator('app-product-detail-page > mat-card').getByRole('heading', { level: 2 })).toBeVisible();
  await expect(page.getByText('Изображений нет.', { exact: true })).toBeVisible();

  await page.goto(origin + '/products/2147483647');
  await expect(page.getByRole('heading', { name: 'Товар не найден', exact: true })).toBeVisible();
  await page.route('**/api/products/11', route => route.fulfill({ status: 500, contentType: 'application/json', body: '{"secret":"do not display"}' }), { times: 1 });
  await page.goto(origin + '/products/11');
  await expect(page.getByRole('button', { name: 'Повторить загрузку' })).toBeVisible();
  await expect(page.locator('body')).not.toContainText('do not display');
  await page.getByRole('button', { name: 'Повторить загрузку' }).click();
  await expect(page.locator('app-product-detail-page > mat-card').getByRole('heading', { level: 2 })).toContainText('Чай 11');

  let notify;
  const started = new Promise(resolve => { notify = resolve; });
  await page.route('**/api/products/12', async route => {
    notify(); const response = await route.fetch();
    await new Promise(resolve => setTimeout(resolve, 700));
    await route.fulfill({ response });
  }, { times: 1 });
  await page.goto(origin + '/products/12'); await started;
  await expect(page.getByRole('progressbar', { name: 'Загрузка товара' })).toBeVisible();
  await expect(page.locator('app-product-detail-page > mat-card').getByRole('heading', { level: 2 })).toHaveText('Кофе 12');
  expect(external).toEqual([]);
});
