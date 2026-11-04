const { test, expect } = require('@playwright/test');
const origin = process.env.AUTH_TEST_ORIGIN;
const storageKey = 'product-import.auth';
test.use({ channel: 'msedge' });

test('real login, invalid password, guarded return URL, reload, expiry and logout', async ({ page }) => {
  test.setTimeout(90000);
  await page.clock.install();
  await page.goto(origin + '/products/42?page=2');
  await expect(page.getByRole('heading', { name: 'Вход', exact: true })).toBeVisible();
  expect(new URL(page.url()).searchParams.get('returnUrl')).toBe('/products/42?page=2');
  await page.getByLabel('Имя пользователя', { exact: true }).fill('worker-fixture');
  await page.getByLabel('Пароль', { exact: true }).fill('wrong-password');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page.getByRole('alert')).toHaveText('Неверное имя пользователя или пароль.');
  await expect(page).toHaveURL(/\/login\?/);
  await page.getByLabel('Пароль', { exact: true }).fill('worker-test-password');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(origin + '/products/42?page=2');
  await expect(page.getByRole('heading', { name: 'Карточка товара' })).toBeVisible();
  await expect(page.getByText('worker-fixture', { exact: true })).toBeVisible();
  // Independently prove the UI obtained a real usable backend token, without printing it.
  const usable = await page.evaluate(async key => {
    const saved = JSON.parse(sessionStorage.getItem(key));
    return (await fetch('/api/products', { headers: { Authorization: 'Bearer ' + saved.accessToken } })).status;
  }, storageKey);
  expect(usable).toBe(200);
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Карточка товара' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Выйти' })).toBeVisible();
  await page.getByRole('button', { name: 'Выйти' }).click();
  await expect(page).toHaveURL(origin + '/login');
  expect(await page.evaluate(key => sessionStorage.getItem(key) === null, storageKey)).toBe(true);
  await page.goto(origin + '/import');
  await expect(page).toHaveURL(/\/login\?/);
  await page.getByLabel('Имя пользователя', { exact: true }).fill('worker-fixture');
  await page.getByLabel('Пароль', { exact: true }).fill('worker-test-password');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(origin + '/import');
  // Browser clock only: no server clocks or JWT configuration are changed.
  await page.clock.fastForward(3700000);
  await expect(page).toHaveURL(/\/login\?/);
  expect(await page.evaluate(key => sessionStorage.getItem(key) === null, storageKey)).toBe(true);
});
