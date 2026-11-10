import assert from 'node:assert/strict';
import test from 'node:test';
import { resolveWorkspace } from './workspaceRoutes.js';

const hof = { system_role: 'member', workspaces: ['hof'] };
const employee = { system_role: 'member', workspaces: ['employee'] };

test('the home URL still opens the workspace assigned to each role', () => {
  const accounts = [
    [{ system_role: 'super_admin' }, 'admin', 'overview'],
    [{ system_role: 'plan_editor' }, 'planning', 'overview'],
    [{ system_role: 'md' }, 'md', 'overview'],
    [hof, 'hof', 'overview'],
    [{ system_role: 'member', workspaces: ['manager'] }, 'delivery', 'overview'],
    [employee, 'delivery', 'tasks'],
  ];
  for (const [user, workspace, page] of accounts) {
    assert.deepEqual(resolveWorkspace('/', user), { workspace, page });
  }
});

test('direct links restore the selected page and enforce workspace access', () => {
  assert.deepEqual(resolveWorkspace('/hof/team', hof), { workspace: 'hof', page: 'team' });
  assert.deepEqual(resolveWorkspace('/delivery/reviews', hof), { workspace: 'delivery', page: 'reviews' });
  assert.deepEqual(resolveWorkspace('/delivery/kpis', employee), { workspace: 'delivery', page: 'kpis' });
  assert.deepEqual(resolveWorkspace('/delivery/activities', employee), { workspace: 'delivery', page: 'activities' });
  assert.deepEqual(resolveWorkspace('/admin/people', hof), { error: 'forbidden' });
  assert.deepEqual(resolveWorkspace('/display', employee), { error: 'forbidden' });
  assert.deepEqual(resolveWorkspace('/display', { system_role: 'member', workspaces: ['manager'] }), { workspace: 'display' });
  assert.deepEqual(resolveWorkspace('/kpi-results', employee), { workspace: 'delivery', page: 'results' });
  assert.deepEqual(resolveWorkspace('/planning/plans', employee), { error: 'forbidden' });
  const planOwner = { ...employee, can_view_plans: true };
  assert.deepEqual(resolveWorkspace('/planning/plans', planOwner), { workspace: 'planning', page: 'plans' });
  assert.deepEqual(resolveWorkspace('/planning/reviewers', planOwner), { error: 'not_found' });
});

test('a typo in a standalone or workspace URL resolves to page not found', () => {
  for (const path of ['/kpi-resultss', '/hof/teamm', '/hof/team/extra']) {
    assert.deepEqual(resolveWorkspace(path, hof), { error: 'not_found' });
  }
});


test('member result links restore the correct workspace sidebar', () => {
  const manager = { system_role: 'member', workspaces: ['manager'] };
  assert.deepEqual(resolveWorkspace('/kpi-results', hof), { workspace: 'hof', page: 'results' });
  assert.deepEqual(resolveWorkspace('/kpi-results', manager), { workspace: 'delivery', page: 'results' });
  assert.deepEqual(resolveWorkspace('/hof/results', hof), { workspace: 'hof', page: 'results' });
  assert.deepEqual(resolveWorkspace('/delivery/results', employee), { workspace: 'delivery', page: 'results' });
  assert.deepEqual(resolveWorkspace('/hof/results', employee), { error: 'forbidden' });
  assert.deepEqual(resolveWorkspace('/hof/personal', hof), { workspace: 'hof', page: 'personal' });
  assert.deepEqual(resolveWorkspace('/kpi-results', { system_role: 'md' }), { workspace: 'kpi-results' });
  assert.deepEqual(resolveWorkspace('/kpi-results', { system_role: 'plan_editor' }), { workspace: 'planning', page: 'results' });
});


test('planning navigation keeps history, allocations and results within authorized routes', () => {
  const editor = { system_role: 'plan_editor' };
  for (const page of ['overview', 'plans', 'history', 'allocations', 'results']) {
    assert.deepEqual(resolveWorkspace(`/planning/${page}`, editor), { workspace: 'planning', page });
  }
  assert.deepEqual(resolveWorkspace('/planning/allocations', { ...employee, can_view_plans: true }), { error: 'forbidden' });
  assert.deepEqual(resolveWorkspace('/planning/results', employee), { error: 'forbidden' });
});
