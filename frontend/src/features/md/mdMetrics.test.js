import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deliveryStats } from './mdMetrics.js';
import { weightedTimeliness } from '../delivery/timeliness.js';
const emptyTiming = { score: null, estimate: null, assessed_weight: 0, scheduled_weight: 0, coverage: 0 };

test('HOF contribution uses plan weights while department completion normalizes selected work', () => {
  const result = deliveryStats([{ weight: 0.6, approved: 50, pending: 2, overdue: true }]);
  assert.deepEqual(result, { timeliness: emptyTiming, points: 30, completion: 50, pending: 2, overdue: 1 });
  assert.equal(deliveryStats([{ weight: 0.6, approved: 50, pending: 0 }, { weight: 0.4, approved: 100, pending: 0 }]).completion, 70);
});
test('empty departments have no completion percentage', () => {
  assert.deepEqual(deliveryStats([]), { timeliness: emptyTiming, points: 0, completion: null, pending: 0, overdue: 0 });
});

test('late work uses unequal task weights and keeps completion separate', () => {
  const rows = [
    { weight: .7, approved: 100, pending: 0, timeliness: { score: 100, estimate: 100, assessed_weight: 1, scheduled_weight: 1 } },
    { weight: .3, approved: 100, pending: 0, timeliness: { score: 50, estimate: 50, assessed_weight: 1, scheduled_weight: 1 } },
  ];
  assert.equal(deliveryStats(rows).completion, 100);
  assert.equal(deliveryStats(rows).timeliness.score, 85);
});
test('nested timing retains assessment coverage and excludes missing deadlines', () => {
  const branch = weightedTimeliness([
    { weight: .4, timeliness: { score: 100, estimate: 100, assessed_weight: 1, scheduled_weight: 1 } },
    { weight: .6, timeliness: { score: null, estimate: 50, assessed_weight: 0, scheduled_weight: 1 } },
  ]);
  const result = weightedTimeliness([{ weight: .7, timeliness: branch }, { weight: .3, timeliness: emptyTiming }]);
  assert.equal(result.score, 100);
  assert.equal(result.estimate, 70);
  assert.ok(Math.abs(result.coverage - 40) < .0001);
});
