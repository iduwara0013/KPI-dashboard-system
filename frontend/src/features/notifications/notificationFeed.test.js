import assert from 'node:assert/strict';
import test from 'node:test';
import { notificationPageSize, appendNotificationPage } from './notificationFeed.js';

test('refresh requests the loaded window instead of dropping older pages', () => {
  assert.equal(notificationPageSize(60), 60);
  assert.equal(notificationPageSize(0), 30);
  assert.equal(notificationPageSize(1000), 300);
});

test('older pages merge without duplicates and retain current server state', () => {
  assert.deepEqual(appendNotificationPage([{ id: 3 }, { id: 2, read_at: null }], [{ id: 2, read_at: 'read' }, { id: 1 }]), [{ id: 3 }, { id: 2, read_at: 'read' }, { id: 1 }]);
});
