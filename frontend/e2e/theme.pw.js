import { test, expect } from '@playwright/test';

test('theme follows the system until a persistent browser preference is chosen', async ({ browser }) => {
  const context = await browser.newContext({ baseURL: 'http://127.0.0.1:5174', colorScheme: 'dark', viewport: { width: 1440, height: 900 } });
  try {
    const page = await context.newPage();
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(page.getByRole('heading', { name: 'Welcome back.' })).toBeVisible();
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await page.screenshot({ path: 'test-results/login-dark.png' });
    await page.getByRole('button', { name: 'Switch to light mode' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.getByRole('button', { name: 'Switch to dark mode' }).click();
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    const other = await context.newPage();
    await other.goto('/');
    await expect(other.locator('html')).toHaveAttribute('data-theme', 'dark');
    await page.getByRole('button', { name: 'Switch to light mode' }).click();
    await expect(other.locator('html')).toHaveAttribute('data-theme', 'light');
  } finally {
    await context.close();
  }
});

test('dark workspace and forms stay usable and preserve the preference after login', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Switch to dark mode' }).click();
  await page.getByLabel(/^Email address/).fill('admin@pulse.test');
  await page.getByLabel(/^Password/).fill('Pulse-Local-2026!');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await page.screenshot({ path: 'test-results/admin-dark.png' });
  await page.getByRole('link', { name: /^People/ }).click();
  await page.getByRole('button', { name: 'Add person', exact: true }).click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await expect(page.getByRole('dialog')).toHaveCSS('background-color', 'rgb(20, 36, 58)');
  await expect(page.getByRole('button', { name: 'Switch to light mode' })).toBeHidden();
  await page.screenshot({ path: 'test-results/person-form-dark.png' });
  await page.getByRole('button', { name: 'Cancel', exact: true }).click();
  await page.getByRole('button', { name: 'Switch to light mode' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
});
