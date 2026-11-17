import test from 'node:test';
import assert from 'node:assert/strict';
import { assignedWeights } from './assignedWeights.js';

test('received activities form 100 percent independently of the source HOF plan', () => {
  const rows = [
    { year: 2026, plan_id: 1, kpa_code: 'DC', kpi_code: 'READY', personal_weight: .024 },
    { year: 2026, plan_id: 1, kpa_code: 'DC', kpi_code: 'READY', personal_weight: .036 },
    { year: 2026, plan_id: 1, kpa_code: 'DC', kpi_code: 'MAINTAIN', personal_weight: .04 },
  ];
  const weights = assignedWeights(rows);
  assert.equal(weights.get(rows[0]).kpa, 1);
  assert.ok(Math.abs(weights.get(rows[0]).kpi - .6) < 1e-10);
  assert.equal(weights.get(rows[0]).activity, .4);
  assert.ok(Math.abs(weights.get(rows[2]).overall - .4) < 1e-10);
});

test('partial assignments normalize only received work and keep years and source plans separate', () => {
  const rows = [
    { year: 2026, plan_id: 1, kpa_code: 'A', kpi_code: 'K', personal_weight: .03 },
    { year: 2026, plan_id: 2, kpa_code: 'A', kpi_code: 'K', personal_weight: .01 },
    { year: 2027, plan_id: 1, kpa_code: 'A', kpi_code: 'K', personal_weight: .9 },
  ];
  const weights = assignedWeights(rows);
  assert.equal(weights.get(rows[0]).kpa, .75);
  assert.equal(weights.get(rows[1]).kpa, .25);
  assert.equal(weights.get(rows[0]).activity, 1);
  assert.equal(weights.get(rows[2]).kpa, 1);
  // Searching displays a subset of these same rows without changing the denominator.
  assert.equal(weights.get(rows.filter(row => row.plan_id === 2)[0]).kpa, .25);
});
