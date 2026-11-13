import { test, expect } from '@playwright/test';

test('login redesign: responsive layout, password visibility and real sign in', async ({ page }) => {
  for (const viewport of [{ width: 1440, height: 900 }, { width: 1366, height: 768 }, { width: 390, height: 844 }]) {
    await page.setViewportSize(viewport);
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Welcome back.' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    if (viewport.width > 760) {
      const height = await page.evaluate(() => ({ content: document.documentElement.scrollHeight, viewport: window.innerHeight }));
      expect(height.content <= height.viewport + 1, JSON.stringify(height)).toBe(true);
      const button = await page.getByRole('button', { name: 'Sign in', exact: true }).boundingBox();
      expect(button.y + button.height).toBeLessThanOrEqual(viewport.height);
    }
    await page.screenshot({ path: `test-results/login-${viewport.width}.png`, fullPage: true });
  }
  await page.getByLabel(/^Email address/).fill('EMP007');
  const password = page.getByLabel(/^Password/);
  await password.fill('Incorrect-password');
  await expect(password).toHaveAttribute('type', 'password');
  await page.getByRole('button', { name: 'Show password', exact: true }).click();
  await expect(password).toHaveAttribute('type', 'text');
  await page.getByRole('button', { name: 'Hide password', exact: true }).click();
  await expect(password).toHaveAttribute('type', 'password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('incorrect');
  await expect(page.getByLabel(/^Email address/)).toHaveValue('EMP007');
  await password.fill('Pulse-Local-2026!');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible();
});
