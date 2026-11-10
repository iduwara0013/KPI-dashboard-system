import assert from 'node:assert/strict';
import test from 'node:test';
import { hasNavigationGuards, registerNavigationGuard, requestNavigation } from './navigationGuard.js';

test('external navigation waits for unsaved work and cancellation preserves the page', () => {
  let confirm, navigated = false;
  const remove = registerNavigationGuard(action => { confirm = action; });
  requestNavigation(() => { navigated = true; });
  assert.equal(hasNavigationGuards(), true);
  assert.equal(navigated, false);
  confirm();
  assert.equal(navigated, true);
  remove();
  assert.equal(hasNavigationGuards(), false);
});

test('unmounted guards are removed and multiple forms must each allow navigation', () => {
  let first, second, navigated = false;
  const removeFirst = registerNavigationGuard(action => { first = action; });
  const removeSecond = registerNavigationGuard(action => { second = action; });
  requestNavigation(() => { navigated = true; });
  first();
  assert.equal(navigated, false);
  second();
  assert.equal(navigated, true);
  removeFirst(); removeSecond();
  requestNavigation(() => { navigated = 'unguarded'; });
  assert.equal(navigated, 'unguarded');
});
