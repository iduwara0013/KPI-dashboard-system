import { afterEach, beforeEach, test } from 'node:test';
import assert from 'node:assert/strict';
import { bindSessionIdentity, onAuthenticationRequired, request } from './api.js';

const originalFetch = globalThis.fetch;
let calls, events, unsubscribe;
const response = (body, status = 200) => new Response(JSON.stringify(body), { status });
function replies(...queue) {
  globalThis.fetch = async (url, options) => {
    calls.push({ url, ...options });
    assert.ok(queue.length, `Unexpected request: ${url}`);
    return queue.shift();
  };
}
beforeEach(() => {
  bindSessionIdentity(null);
  bindSessionIdentity({ id: 1, email: 'editor@example.test' });
  calls = []; events = [];
  unsubscribe = onAuthenticationRequired(event => events.push(event));
});
afterEach(() => { unsubscribe(); bindSessionIdentity(null); globalThis.fetch = originalFetch; });

test('save checks current session and sends identity and fresh CSRF token', async () => {
  replies(response({ user: { id: 1 }, csrf_token: 'fresh' }), response({ saved: true }));
  assert.deepEqual(await request('/planning/plans', { method: 'POST', body: { title: 'Draft' } }), { saved: true });
  assert.deepEqual(calls.map(x => x.url), ['/api/session', '/api/planning/plans']);
  assert.equal(calls[1].headers['X-Workspace-User'], '1');
  assert.equal(calls[1].headers['X-CSRF-TOKEN'], 'fresh');
  assert.equal(calls[1].credentials, 'same-origin');
  assert.deepEqual(events, []);
});

for (const [user, reason] of [[null, 'expired'], [{ id: 2 }, 'changed']]) {
  test(`${reason} session stops save before mutation`, async () => {
    replies(response({ user }));
    await assert.rejects(request('/planning/plans', { method: 'POST', body: {} }), { status: 401 });
    assert.equal(calls.length, 1);
    assert.deepEqual(events, [{ reason }]);
  });
}

test('session lost after preflight also opens recovery without replay', async () => {
  replies(response({ user: { id: 1 } }), response({ message: 'Unauthenticated.' }, 401));
  await assert.rejects(request('/planning/plans', { method: 'POST', body: {} }), { status: 401 });
  assert.equal(calls.length, 2);
  assert.deepEqual(events, [{ reason: 'expired' }]);
});

test('CSRF retry cannot replay save after another account signs in', async () => {
  replies(response({ user: { id: 1 } }), response({}, 419), response({ user: { id: 2 }, csrf_token: 'other' }), response({ user: { id: 2 } }));
  await assert.rejects(request('/planning/plans', { method: 'POST', body: {} }), { status: 401 });
  assert.equal(calls.filter(x => x.method === 'POST').length, 1);
  assert.deepEqual(events, [{ reason: 'changed' }]);
});

test('login remains available and never automatically replays a pending save', async () => {
  replies(response({ user: { id: 1 }, csrf_token: 'restored' }));
  await request('/login', { method: 'POST', body: { email: 'editor@example.test', password: 'example' } });
  assert.deepEqual(calls.map(x => x.url), ['/api/login']);
  assert.equal(calls[0].headers['X-Workspace-User'], undefined);
  assert.deepEqual(events, []);
});

test('permission and validation errors remain normal form errors', async () => {
  for (const status of [403, 422]) {
    replies(response({ user: { id: 1 } }), response({ message: 'Cannot save', errors: { title: ['Invalid'] } }, status));
    await assert.rejects(request('/planning/plans', { method: 'POST', body: {} }), { status, message: 'Cannot save' });
  }
  assert.deepEqual(events, []);
});
