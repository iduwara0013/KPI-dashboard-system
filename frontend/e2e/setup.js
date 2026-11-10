import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { database, testEnvironment } from '../playwright.config.js';

export default function setup() {
  const expected = path.resolve('test-results/kpi-browser.sqlite');
  if (database !== expected || !database.endsWith('kpi-browser.sqlite')) throw new Error('Browser tests require their dedicated SQLite fixture.');
  fs.mkdirSync(path.dirname(database), { recursive: true });
  fs.writeFileSync(database, '');
  const result = spawnSync('php', ['artisan', 'migrate', '--seed', '--force'], { cwd: path.resolve('../backend'), env: { ...process.env, ...testEnvironment }, encoding: 'utf8' });
  if (result.status !== 0) throw new Error(result.stdout + result.stderr);
}
