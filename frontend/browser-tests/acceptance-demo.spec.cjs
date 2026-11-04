const { test, expect } = require('@playwright/test');
test.use({ channel: 'msedge', actionTimeout: 10000 });
test('clean production demo: guard/direct card/reload/local PNG and 48 fixtures', async ({ page }) => {
  await page.goto(process.env.AUTH_TEST_ORIGIN + '/products/1?page=2&limit=20');
  await expect(page.getByRole('heading', { name: 'Вход', exact: true })).toBeVisible();
  await page.getByLabel('Имя пользователя', { exact: true }).fill('fixture-user');
  await page.getByLabel('Пароль', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/products\/1\?page=2&limit=20$/);
  await expect(page.getByRole('heading', { name: 'Футболка 01', exact: true })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Футболка 01', exact: true })).toBeVisible();
  await expect.poll(() => page.locator('.gallery img').evaluate(img => img.complete && img.naturalWidth === 24)).toBe(true);
  await page.getByRole('link', { name: 'К списку товаров', exact: true }).click();
  await expect(page.getByText('Найдено: 48', { exact: true })).toBeVisible();
  await expect(page.locator('tr.product-row')).toHaveCount(20);
});
