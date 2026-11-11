const pages = {
  admin: ['overview', 'people', 'departments', 'reporting', 'access', 'activity'],
  planning: ['overview', 'plans', 'history', 'allocations', 'results'],
  md: ['overview', 'departments', 'hofs', 'work'],
  hof: ['overview', 'breakdown', 'activities', 'own', 'delegated', 'team', 'reviews', 'personal', 'results'],
  delivery: ['overview', 'activities', 'kpis', 'tasks', 'reviews', 'team', 'personal', 'results'],
};

export function workspacePath(workspace, page) {
  return `/${workspace}/${page}`;
}

export function defaultDeliveryPage(user) {
  if (user.workspaces?.includes('manager')) return 'overview';
  return user.workspaces?.includes('employee') ? 'tasks' : 'reviews';
}

export function resolveWorkspace(pathname, user) {
  const path = pathname.replace(/\/$/, '') || '/';
  const role = user.system_role;
  const member = role === 'member';
  const hof = member && user.workspaces?.includes('hof');
  const delivery = member && user.workspaces?.some(role => ['hof', 'manager', 'employee'].includes(role));
  if (path === '/delivery/reviews' && hof) return { workspace: 'hof', page: 'reviews' };
  const home = { super_admin: 'admin', plan_editor: 'planning', md: 'md' }[role]
    ?? (hof ? 'hof' : 'delivery');
  const access = {
    admin: role === 'super_admin',
    planning: role === 'plan_editor' || (delivery && user.can_view_plans),
    md: role === 'md',
    hof,
    delivery,
    display: role === 'md' || (member && user.workspaces?.some(role => ['hof', 'manager'].includes(role))),
    'kpi-results': ['md', 'plan_editor', 'member'].includes(role),
  };

  if (path === '/display' || path === '/kpi-results') {
    const workspace = path.slice(1);
    if (!access[workspace]) return { error: 'forbidden' };
    if (workspace === 'kpi-results' && role === 'plan_editor') return { workspace: 'planning', page: 'results' };
    if (workspace === 'kpi-results' && delivery) return { workspace: hof ? 'hof' : 'delivery', page: 'results' };
    return { workspace };
  }

  const [workspace, page = workspace === 'delivery' ? defaultDeliveryPage(user) : 'overview', ...extra]
    = path === '/' ? [home] : path.slice(1).split('/');
  if (extra.length || !Object.hasOwn(pages, workspace) || !pages[workspace].includes(page)) return { error: 'not_found' };
  const editorOnly = workspace === 'planning' && page === 'allocations';
  if (!access[workspace] || (editorOnly && role !== 'plan_editor')) return { error: 'forbidden' };
  return { workspace, page };
}
