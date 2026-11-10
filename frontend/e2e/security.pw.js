import { test, expect } from '@playwright/test';

test('HTTP security: cross-site CSRF, member permissions and signed-out session replay', async ({ playwright }) => {
  const client = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:5174', extraHTTPHeaders: { Accept: 'application/json' } });
  try {
    const plain = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:5174' });
    try {
      const guest = await plain.get('/api/admin/workspace');
      expect(guest.status()).toBe(401);
      expect((await guest.json()).message).toBe('Unauthenticated.');
    } finally {
      await plain.dispose();
    }
    expect((await client.get('/api/admin/workspace')).status()).toBe(401);
    const session = await (await client.get('/api/session')).json();
    const credentials = { email: 'emp007@example.test', password: 'Pulse-Local-2026!' };
    expect((await client.post('/api/login', { headers: { 'Sec-Fetch-Site': 'cross-site' }, data: credentials })).status()).toBe(419);
    expect((await client.post('/api/login', {
      headers: { 'X-CSRF-TOKEN': session.csrf_token }, data: credentials,
    })).ok()).toBeTruthy();
    expect((await client.get('/api/delivery/workspace')).status()).toBe(200);
    expect((await client.get('/api/admin/workspace')).status()).toBe(403);
    expect((await client.post('/api/admin/users', {
      headers: { 'X-CSRF-TOKEN': (await (await client.get('/api/session')).json()).csrf_token },
      data: { system_role: 'super_admin' },
    })).status()).toBe(403);
    const signedIn = await client.storageState();
    const current = await (await client.get('/api/session')).json();
    expect((await client.post('/api/logout', { headers: { 'X-CSRF-TOKEN': current.csrf_token } })).ok()).toBeTruthy();
    expect((await client.get('/api/delivery/workspace')).status()).toBe(401);
    const replay = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:5174', extraHTTPHeaders: { Accept: 'application/json' }, storageState: signedIn });
    try {
      expect((await replay.get('/api/delivery/workspace')).status()).toBe(401);
    } finally {
      await replay.dispose();
    }
  } finally {
    await client.dispose();
  }
});

test('HTTP security: password resets and access changes revoke other sessions', async ({ playwright }) => {
  const settings = { baseURL: 'http://127.0.0.1:5174', extraHTTPHeaders: { Accept: 'application/json' } };
  const admin = await playwright.request.newContext(settings);
  const person = await playwright.request.newContext(settings);
  const initialPassword = 'Security initial passphrase 2026';
  const newPassword = 'Security replacement passphrase 2026';
  async function login(client, email, password) {
    const session = await (await client.get('/api/session')).json();
    const response = await client.post('/api/login', { headers: { 'X-CSRF-TOKEN': session.csrf_token }, data: { email, password } });
    expect(response.ok(), await response.text()).toBeTruthy();
  }
  async function save(url, data, method = 'post') {
    const session = await (await admin.get('/api/session')).json();
    const response = await admin[method](url, { headers: { 'X-CSRF-TOKEN': session.csrf_token }, data });
    expect(response.ok(), await response.text()).toBeTruthy();
    return response.json();
  }
  try {
    await login(admin, 'admin@pulse.test', 'Pulse-Local-2026!');
    const details = { name: 'Security Test Editor', employee_number: 'SEC-EDITOR', email: 'security-editor@example.test', designation: 'Plan Editor', system_role: 'plan_editor', is_active: true, memberships: [] };
    const created = await save('/api/admin/users', { ...details, password: initialPassword });
    const url = `/api/admin/users/${created.user.id}`;
    await login(person, details.email, initialPassword);
    // Reset immediately after login, before the person's first protected request.
    const oldState = await person.storageState();
    await save(url, { ...details, password: newPassword }, 'put');
    expect((await person.get('/api/planning/workspace')).status()).toBe(401);
    const replay = await playwright.request.newContext({ ...settings, storageState: oldState });
    try {
      expect((await replay.get('/api/planning/workspace')).status()).toBe(401);
    } finally {
      await replay.dispose();
    }
    await login(person, details.email, newPassword);
    await save(url, { ...details, is_active: false }, 'put');
    await save(url, details, 'put');
    expect((await person.get('/api/planning/workspace')).status()).toBe(401);
    await login(person, details.email, newPassword);
    await save(url, { ...details, system_role: 'super_admin' }, 'put');
    expect((await person.get('/api/admin/workspace')).status()).toBe(401);
    await login(person, details.email, newPassword);
    expect((await person.get('/api/admin/workspace')).status()).toBe(200);
  } finally {
    await admin.dispose();
    await person.dispose();
  }
});
