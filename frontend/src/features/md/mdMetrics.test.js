import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deliveryStats } from './mdMetrics.js';

test('HOF contribution uses plan weights while department completion normalizes selected work', () => {
  const result = deliveryStats([{ weight: 0.6, approved: 50, pending: 2, overdue: true }]);
  assert.deepEqual(result, { points: 30, completion: 50, pending: 2, overdue: 1 });
  assert.equal(deliveryStats([{ weight: 0.6, approved: 50, pending: 0 }, { weight: 0.4, approved: 100, pending: 0 }]).completion, 70);
});
test('empty departments have no completion percentage', () => {
  assert.deepEqual(deliveryStats([]), { points: 0, completion: null, pending: 0, overdue: 0 });
});
