import { defineConfig } from '@playwright/test';
import path from 'node:path';

export const database = path.resolve('test-results/kpi-browser.sqlite');
export const testEnvironment = {
  APP_ENV: 'testing', APP_DEBUG: 'true', APP_URL: 'http://127.0.0.1:5174',
  DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '',
  SESSION_DRIVER: 'file', SESSION_COOKIE: 'cpstl_browser_review', SESSION_DOMAIN: '',
  CACHE_STORE: 'array', BCRYPT_ROUNDS: '4', DEMO_ADMIN_PASSWORD: 'Pulse-Local-2026!',
};
export default defineConfig({
  testDir: './e2e', testMatch: '**/*.pw.js', fullyParallel: false, workers: 1,
  timeout: 120000, expect: { timeout: 15000 },
  globalSetup: './e2e/setup.js',
  use: { baseURL: 'http://127.0.0.1:5174', channel: 'chrome', headless: true, actionTimeout: 15000, navigationTimeout: 20000, viewport: { width: 1440, height: 1000 }, trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  reporter: [['list'], ['html', { open: 'never' }]],
  webServer: { command: 'php -S 127.0.0.1:5174 -t dist serve-built.php', url: 'http://127.0.0.1:5174/cpstl-logo.png', env: testEnvironment, reuseExistingServer: false, timeout: 30000, stdout: 'ignore', stderr: 'ignore' },
});
