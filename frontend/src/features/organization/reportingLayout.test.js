import assert from 'node:assert/strict';
import test from 'node:test';
import { reportingLayout, reportingConnector, safePosition } from './reportingLayout.js';

const people = [{ id: 1, name: 'CIO' }, { id: 2, name: 'A manager' }, { id: 3, name: 'B manager' }, { id: 4, name: 'Z engineer' }, { id: 5, name: 'A engineer' }];
const links = [{ manager_id: 1, user_id: 2 }, { manager_id: 1, user_id: 3 }, { manager_id: 2, user_id: 4 }, { manager_id: 3, user_id: 5 }];

test('children follow their own manager instead of crossing branches in alphabetical order', () => {
  const { positions } = reportingLayout(people, links, new Set(), 1000);
  assert.ok(positions[2].y < positions[3].y);
  assert.ok(positions[4].y < positions[5].y);
  assert.equal(positions[2].y, positions[4].y);
  assert.equal(positions[3].y, positions[5].y);
});

test('measured card heights prevent sibling overlap and keep collapsed branches hidden', () => {
  const heights = { 1: 240, 2: 220, 3: 190, 4: 150, 5: 260 };
  const { positions, height } = reportingLayout(people, links, new Set(), 1000, heights);
  for (const left of people) for (const right of people) {
    if (left.id >= right.id || positions[left.id].x !== positions[right.id].x) continue;
    const a = positions[left.id], b = positions[right.id];
    assert.ok(a.y + heights[left.id] <= b.y || b.y + heights[right.id] <= a.y);
  }
  assert.ok(height >= positions[5].y + heights[5]);
  const collapsed = reportingLayout(people, links, new Set(['2']), 1000, heights).positions;
  assert.equal(collapsed[4], undefined);
  assert.ok(collapsed[5]);
});

test('manual positioning moves only the selected card and expands chart bounds', () => {
  const base = reportingLayout(people, links, new Set(), 1000);
  const moved = reportingLayout(people, links, new Set(), 1000, {}, { 2: { x: 1400, y: 1800 } });
  assert.deepEqual(moved.positions[2], { x: 1400, y: 1800 });
  assert.deepEqual(moved.positions[3], base.positions[3]);
  assert.ok(moved.width > 1600 && moved.height > 1900);
  assert.deepEqual(safePosition({ x: -50, y: -10 }), { x: 16, y: 16 });
  assert.equal(safePosition({ x: Infinity, y: 0 }), null);
});

test('arrows use actual card centers and switch ports when the child is moved above or left', () => {
  assert.equal(reportingConnector({ x: 30, y: 30 }, { x: 360, y: 300 }, 200, 120), 'M 270 130 H 315 V 360 H 360');
  assert.equal(reportingConnector({ x: 360, y: 30 }, { x: 30, y: 300 }, 200, 120), 'M 360 130 H 315 V 360 H 270');
  assert.equal(reportingConnector({ x: 30, y: 300 }, { x: 30, y: 30 }, 200, 120), 'M 150 300 V 225 H 150 V 150');
  assert.match(reportingConnector({ x: 30, y: 30 }, { x: 690, y: 30 }), /V 8/);
});
