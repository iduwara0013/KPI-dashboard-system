import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { testEnvironment } from '../playwright.config.js';

test('EPF first login requires a new password before workspace access and survives reload', async ({ browser, page }) => {
  const admin = await browser.newContext({ baseURL: 'http://127.0.0.1:5174' });
  let session = await (await admin.request.get('/api/session')).json();
  const login = await admin.request.post('/api/login', { headers: { 'X-CSRF-TOKEN': session.csrf_token }, data: { email: 'admin@pulse.test', password: 'Pulse-Local-2026!' } });
  expect(login.ok()).toBe(true);
  session = await (await admin.request.get('/api/session')).json();
  const org = await (await admin.request.get('/api/admin/workspace')).json();
  const department = org.departments.find(row => row.code === 'IT');
  const created = await admin.request.post('/api/admin/users', { headers: { 'X-CSRF-TOKEN': session.csrf_token, 'X-Workspace-User': String(session.user.id) }, data: {
    name: 'First Login Employee', employee_number: '11473', email: '11473@directory.cpstl.invalid', designation: 'Systems Engineer', system_role: 'member', is_active: true, password: 'Initial-123',
    memberships: [{ department_id: department.id, responsibility: 'employee' }],
  } });
  expect(created.ok(), await created.text()).toBe(true);
  const source = path.resolve('test-results/first-login-employees.csv');
  fs.writeFileSync(source, 'epf,firstname,lastname,contact,function,personnel_area,designation\n11473,First Login,Employee,,Information Technology,Kolonnawa,Systems Engineer\n');
  const initialized = spawnSync('php', ['artisan', 'employees:initial-passwords', source, '--apply'], { cwd: path.resolve('../backend'), env: { ...process.env, ...testEnvironment }, encoding: 'utf8' });
  expect(initialized.status, initialized.stderr + initialized.stdout).toBe(0);
  await page.goto('/');
  await page.getByLabel(/^Email address/).fill('11473');
  await page.getByLabel(/^Password/).fill('11473');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Set your new password' })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Task delivery', exact: true })).toHaveCount(0);
  expect((await page.request.get('/api/delivery/workspace')).status()).toBe(423);
  await page.goto('/delivery/tasks');
  await expect(page.getByRole('heading', { name: 'Set your new password' })).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
  await page.screenshot({ path: 'test-results/first-login-mobile.png', fullPage: true });
  await page.getByLabel(/^Current password/).fill('11473');
  await page.getByLabel(/^New password/).fill('New-1234');
  await page.getByLabel(/^Confirm new password/).fill('Mismatch');
  await page.getByRole('button', { name: 'Save password & continue', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('do not match');
  await page.getByLabel(/^Confirm new password/).fill('New-1234');
  await page.getByRole('button', { name: 'Save password & continue', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'My work', exact: true, level: 1 })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('heading', { name: 'My work', exact: true, level: 1 })).toBeVisible();
  await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await page.getByLabel(/^Email address/).fill('11473');
  await page.getByLabel(/^Password/).fill('11473');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('incorrect');
  await page.getByLabel(/^Password/).fill('New-1234');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'My work', exact: true, level: 1 })).toBeVisible();
  await admin.close();
});
