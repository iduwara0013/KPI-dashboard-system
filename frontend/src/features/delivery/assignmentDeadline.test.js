import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initialAssignmentDeadline } from './assignmentDeadline.js';

test('whole KPI uses the latest activity plan date regardless of activity order', () => {
  assert.equal(initialAssignmentDeadline('kpi', { due_date: '2026-11-01', activities: [{ due_date: '2026-11-01' }, { due_date: null }, { due_date: '2026-12-15' }, { due_date: '2026-11-20' }] }), '2026-12-15');
});
test('single activity uses its own plan date', () => {
  assert.equal(initialAssignmentDeadline('activity', { due_date: '2026-11-15' }), '2026-11-15');
});
test('editing preserves the saved deadline including an explicitly empty date', () => {
  assert.equal(initialAssignmentDeadline('kpi', { delegation: { due_date: '2026-10-25 00:00:00' }, activities: [{ due_date: '2026-12-15' }] }), '2026-10-25');
  assert.equal(initialAssignmentDeadline('activity', { assignment: { due_date: null }, due_date: '2026-11-15' }), '');
});
test('no plan dates leaves the deadline empty', () => {
  assert.equal(initialAssignmentDeadline('kpi', { activities: [{ due_date: null }] }), '');
  assert.equal(initialAssignmentDeadline('activity', {}), '');
});
