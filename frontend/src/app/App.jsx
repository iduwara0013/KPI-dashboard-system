import useWorkspaceHistory from './useWorkspaceHistory.js';
import { requestNavigation } from '../services/navigationGuard.js';
import DashboardCharts from '../components/DashboardCharts.jsx';
import OrganizationChart from '../features/organization/OrganizationChart.jsx';
import DepartmentReporting from '../features/organization/DepartmentReporting.jsx';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  Activity, ArrowDownToLine, ArrowRight, ArrowUpRight, Building2, Check,
  CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, CircleHelp, FolderTree,
  LayoutDashboard, LoaderCircle, LogOut, Menu, Network, Pencil, Plus, Search,
  ShieldCheck, SlidersHorizontal, Sparkles, Trash2, Users, X,
} from 'lucide-react';
import { bindSessionIdentity, checkSessionIdentity, onAuthenticationRequired, request } from '../services/api.js';
import { Avatar, Brand, Badge, Empty, ErrorBox, Field, Modal, RouteLink } from '../components/ui.jsx';
import PlanWorkspace from '../features/planning/PlanWorkspace.jsx';
import HofWorkspace from '../features/hof/HofWorkspace.jsx';
import DeliveryWorkspace from '../features/delivery/DeliveryWorkspace.jsx';
import SessionRecovery from '../features/auth/SessionRecovery.jsx';
import Login from '../features/auth/Login.jsx';
import MdWorkspace from '../features/md/MdWorkspace.jsx';
import MonthlyKpiWorkspace from '../features/kpi/MonthlyKpiWorkspace.jsx';
import NotificationCenter from '../features/notifications/NotificationCenter.jsx';
import TvDisplay from '../features/display/TvDisplay.jsx';
import { resolveWorkspace, workspacePath } from './workspaceRoutes.js';

const roles = { super_admin: 'System admin', md: 'MD / executive viewer', plan_editor: 'Plan editor', member: 'Team member' };
const responsibilities = { hof: 'Head of function', manager: 'Manager', employee: 'Employee' };
const responsibilityTitles = [
  'CIO',
  'Manager',
  'Senior Deputy Manager IS (Info Security)',
  'Senior Deputy Manager IS (Solutions)',
  'Deputy Manager IS (Quality Assurance)',
  'Deputy Manager IS (Solution)',
  'Deputy Manager IS (System Admin)',
  'Deputy Manager IS (Network)',
  'System Controller (Data)',
  'Head of Data Center',
  'Assistant Manager Information System',
  'Assistant Manager IS (Solutions)',
  'Assistant Head of Data Center',
  'Junior Operations Executive',
  'Systems Engineer',
];
const navigation = [
  ['overview', 'Overview', LayoutDashboard], ['people', 'People', Users],
  ['departments', 'Departments', Building2], ['reporting', 'Reporting Structure', Network], ['access', 'Access & roles', ShieldCheck],
  ['activity', 'Activity log', Activity],
];
const colors = ['#c5120c', '#243a5e', '#467ea5', '#b78639', '#7863b7', '#66758b'];
const blankData = { users: [], departments: [], memberships: [], reporting: [], activity: [] };
const initialUser = { name: '', email: '', employee_number: '', designation: '', grade: '', system_role: 'member', is_active: true, password: '', memberships: [] };
const initialDepartment = { name: '', code: '', description: '', color: colors[0], is_active: true };
const idEquals = (a, b) => String(a) === String(b);
const memberOf = (data, userId) => data.memberships.filter(x => idEquals(x.user_id, userId));
const userById = (data, id) => data.users.find(x => idEquals(x.id, id));
const departmentById = (data, id) => data.departments.find(x => idEquals(x.id, id));
const memberCount = (data, id) => data.memberships.filter(x => idEquals(x.department_id, id) && userById(data, x.user_id)?.is_active).length;

export default function App() {
  const { pathname, historyVisit, navigate } = useWorkspaceHistory();
  const [session, setSession] = useState(undefined);
  const [bootError, setBootError] = useState(null);
  const [recovery, setRecovery] = useState(null);
  const [notificationTarget, setNotificationTarget] = useState(null);
  const sessionRef = useRef(session), recovering = useRef(false);
  sessionRef.current = session;
  const loadSession = () => { setBootError(null); request('/session').then(x => { bindSessionIdentity(x.user); setSession(x.user); }).catch(setBootError); };
  useEffect(loadSession, []);
  useEffect(() => { setNotificationTarget(null); }, [historyVisit]);
  useEffect(() => {
    const unsubscribe = onAuthenticationRequired(event => { if (sessionRef.current) { recovering.current = true; setRecovery(event); } });
    const check = () => { if (sessionRef.current && !recovering.current && document.visibilityState === 'visible') checkSessionIdentity().catch(() => {}); };
    window.addEventListener('focus', check); document.addEventListener('visibilitychange', check);
    return () => { unsubscribe(); window.removeEventListener('focus', check); document.removeEventListener('visibilitychange', check); };
  }, []);
  function updateSession(user) {
    if (!user && recovering.current) return;
    if (!user) navigate('/', true);
    bindSessionIdentity(user);
    setNotificationTarget(null);
    setSession(user);
  }
  function openNotification(target) {
    const resultsRoute = resolveWorkspace('/kpi-results', session);
    const next = target.workspace === 'delivery' && target.page === 'reviews' && session.workspaces?.includes('hof') ? '/hof/reviews' : target.workspace === 'results' ? (resultsRoute.page ? workspacePath(resultsRoute.workspace, resultsRoute.page) : '/kpi-results') : target.workspace === 'md'
      ? workspacePath('md', 'work')
      : workspacePath('delivery', target.page === 'activities' && !session.workspaces?.includes('manager') ? 'tasks' : target.page);
    if (resolveWorkspace(next, session).error) return;
    requestNavigation(() => {
      navigate(next);
      setNotificationTarget({ ...target, token: Date.now() });
    });
  }
  function restored(user) {
    if (!user || String(user.id) !== String(sessionRef.current.id) || user.system_role !== sessionRef.current.system_role) throw new Error('Account access changed. Sign out and reopen the correct workspace.');
    bindSessionIdentity(user); recovering.current = false; setSession(user); setRecovery(null);
  }
  function discardSession() { recovering.current = false; setRecovery(null); updateSession(null); }
  if (bootError) return <div className="boot"><img className="brand-logo" src="/cpstl-logo.png" alt="CPSTL logo" width="58" height="58" /><h2>Let’s reconnect your workspace</h2><ErrorBox error={bootError} /><button className="btn btn-primary" onClick={loadSession}>Try again</button></div>;
  if (session === undefined) return <div className="boot"><LoaderCircle className="spin" /><p>Opening your workspace…</p></div>;
  if (!session) return <Login onLogin={updateSession} />;
  let workspace;
  const route = resolveWorkspace(pathname, session);
  const props = {
    session, onSession: updateSession, page: route.page,
    onPage: page => {
      const path = workspacePath(route.workspace, page);
      if (route.workspace === 'planning' && (route.page === 'personal' || page === 'personal')) window.location.assign(path);
      else navigate(path);
    },
  };
  const standalone = ['display', 'kpi-results'].includes(route.workspace);
  if (route.error) {
    workspace = <div className="boot">
      <img className="brand-logo" src="/cpstl-logo.png" alt="CPSTL logo" width="58" height="58" />
      <h1>{route.error === 'forbidden' ? 'Access unavailable' : 'Page not found'}</h1>
      <p>{route.error === 'forbidden' ? 'This page is unavailable for your account.' : 'Check the address or return to your workspace.'}</p>
      <a className="btn btn-primary" href="/">Back to workspace</a>
    </div>;
  } else if (route.workspace === 'display') {
    workspace = <TvDisplay paused={!!recovery} />;
  } else if (route.workspace === 'kpi-results') {
    workspace = <MonthlyKpiWorkspace session={session} notificationTarget={notificationTarget} />;
  } else if (route.workspace === 'md') {
    workspace = <MdWorkspace key={historyVisit} {...props} notificationTarget={notificationTarget} />;
  } else if (route.workspace === 'planning') {
    const onWork = session.system_role === 'member' ? () => window.location.assign('/') : undefined;
    workspace = <PlanWorkspace key={historyVisit} {...props} notificationTarget={notificationTarget} onWork={onWork} />;
  } else if (route.workspace === 'hof') {
    workspace = <HofWorkspace key={historyVisit} {...props} notificationTarget={notificationTarget} />;
  } else if (route.workspace === 'delivery') {
    const onHof = session.workspaces?.includes('hof') ? () => requestNavigation(() => { setNotificationTarget(null); navigate('/hof/overview'); }) : null;
    workspace = <DeliveryWorkspace key={historyVisit} {...props} notificationTarget={notificationTarget} onHof={onHof} />;
  } else {
    workspace = <Workspace key={historyVisit} {...props} />;
  }
  return <><div inert={recovery ? true : undefined} aria-hidden={recovery ? true : undefined}>{workspace}{!standalone && !route.error && <NotificationCenter key={session.id} locationKey={pathname} user={session} paused={!!recovery} onOpen={openNotification} />}</div>{recovery && <SessionRecovery user={session} reason={recovery.reason} onRestored={restored} onDiscard={discardSession} />}</>;
}

function Workspace({ session, onSession, page, onPage }) {
  const [data, setData] = useState(blankData);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(null);
  const [query, setQuery] = useState('');
  const [departmentFilter, setDepartmentFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');
  const [modal, setModal] = useState(null);
  const [toast, setToast] = useState('');
  const [mobileNav, setMobileNav] = useState(false);
  const searchRef = useRef();
  const notify = message => setToast(message);
  function navigate(next) {
    if (page !== next && (page === 'reporting' || next === 'reporting')) {
      window.location.assign(workspacePath('admin', next));
      return;
    }
    onPage(next);
    setQuery(''); setDepartmentFilter('all'); setStatusFilter('all'); setMobileNav(false);
  }
  function savedReporting(departmentId, links, memberships) {
    setData(previous => ({
      ...previous, reporting: [...previous.reporting.filter(link => !idEquals(link.department_id, departmentId)), ...links],
      memberships: [...previous.memberships.filter(membership => !idEquals(membership.department_id, departmentId)), ...memberships],
    }));
  }
  async function refresh() {
    setLoadError(null);
    try { const result = await request('/admin/workspace'); setData(result); return result; }
    catch (error) { if (error.status === 401) onSession(null); setLoadError(error); throw error; }
    finally { setLoading(false); }
  }
  useEffect(() => { refresh().catch(() => {}); }, []);
  useEffect(() => { if (!toast) return; const t = setTimeout(() => setToast(''), 4500); return () => clearTimeout(t); }, [toast]);
  useEffect(() => {
    const handle = e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); searchRef.current?.focus(); } };
    window.addEventListener('keydown', handle); return () => window.removeEventListener('keydown', handle);
  }, []);
  async function saved(message) {
    setModal(null); notify(message);
    try { const result = await refresh(); const me = result.users.find(x => x.id === session.id); if (me) onSession(me); }
    catch { /* The page shows a retry panel without losing the success notification. */ }
  }
  async function logout() {
    try { await request('/logout', { method: 'POST' }); onSession(null); }
    catch (error) { notify(error.message); }
  }
  const title = navigation.find(x => x[0] === page)?.[1];
  const editUser = user => setModal({ kind: 'user', user });
  const editDepartment = department => setModal({ kind: 'department', department });
  const activeUsers = data.users.filter(x => x.is_active);
  const activeDepartments = data.departments.filter(x => x.is_active);
  const hofIds = new Set(data.memberships.filter(x => x.responsibility === 'hof' && userById(data, x.user_id)?.is_active).map(x => x.user_id));
  const uncovered = activeDepartments.filter(d => !data.memberships.some(m => idEquals(m.department_id, d.id) && m.responsibility === 'hof' && userById(data, m.user_id)?.is_active));
  const unassigned = activeUsers.filter(u => u.system_role === 'member' && !memberOf(data, u.id).length);
  function exportPeople() {
    const safe = value => {
      let text = String(value ?? '');
      if (/^\s*[=+@-]|^[\t\r\n]/.test(text)) text = "'" + text;
      return '"' + text.replaceAll('"', '""') + '"';
    };
    const rows = [['Employee number', 'Name', 'Email', 'Designation', 'Grade', 'System role', 'Status', 'Departments'], ...filteredUsers.map(u => [u.employee_number, u.name, u.email.endsWith('@pending.cpstl.invalid') ? '' : u.email, u.designation, u.grade, roles[u.system_role], u.is_active ? 'Active' : 'Inactive', memberOf(data, u.id).map(m => `${departmentById(data, m.department_id)?.name} (${responsibilities[m.responsibility]})`).join('; ')])];
    const blob = new Blob(['\uFEFF' + rows.map(row => row.map(safe).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'cpstl-people.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    notify('People directory exported.');
  }
  const filteredUsers = useMemo(() => data.users.filter(u => {
    const matchesQuery = [u.name, u.email, u.employee_number, u.designation, u.grade].some(v => String(v || '').toLowerCase().includes(query.toLowerCase()));
    return matchesQuery && (departmentFilter === 'all' || memberOf(data, u.id).some(m => idEquals(m.department_id, departmentFilter))) && (statusFilter === 'all' || u.is_active === (statusFilter === 'active'));
  }), [data, query, departmentFilter, statusFilter]);

  return <div className="app-shell">
    {mobileNav && <button className="nav-scrim" aria-label="Close navigation" onClick={() => setMobileNav(false)} />}
    <aside className={`sidebar ${mobileNav ? 'sidebar-open' : ''}`}>
      <RouteLink className="brand" href="/admin/overview" aria-label="CPSTL administration overview" onNavigate={() => navigate('overview')}><Brand /></RouteLink>
      <div className="workspace-label"><span className="workspace-dot" /> CPSTL WORKSPACE</div>
      <div className="nav-caption">ADMINISTRATION</div>
      <nav aria-label="Administration">{navigation.map(([id, label, Icon]) => <RouteLink key={id} href={workspacePath('admin', id)} aria-current={page === id ? 'page' : undefined} className={`nav-item ${page === id ? 'nav-active' : ''}`} onNavigate={() => navigate(id)}><Icon size={19} /><span>{label}</span>{id === 'people' && <span className="nav-count">{data.users.length}</span>}</RouteLink>)}</nav>
      <div className="sidebar-bottom"><div className="sidebar-tip"><span className="tip-icon"><Network size={22} /></span><strong>A clear structure.<br />A stronger foundation.</strong><p>Connect every person to the right department and responsibility.</p><button onClick={() => setModal({ kind: 'help' })}>Setup guide <ArrowUpRight size={15} /></button></div>
        <div className="sidebar-user"><Avatar name={session.name} small /><span><strong>{session.name}</strong><small>System administrator</small></span><button className="icon-button" title="Sign out" aria-label="Sign out" onClick={logout}><LogOut size={17} /></button></div>
      </div>
    </aside>
    <div className="workspace">
      <header className="topbar"><div className="breadcrumb"><button className="icon-button mobile-menu" aria-label="Open navigation" onClick={() => setMobileNav(true)}><Menu size={22} /></button><span>CPSTL</span><ChevronRight size={14} /><strong>{title}</strong></div>
        <div className="topbar-right">{page !== 'reporting' && <div className="global-search"><Search size={17} /><input ref={searchRef} value={query} onChange={e => { setQuery(e.target.value); onPage('people'); }} placeholder="Search people…" aria-label="Search people" /><kbd>⌘ K</kbd></div>}<button className="icon-button help-button" aria-label="Open setup guide" onClick={() => setModal({ kind: 'help' })}><CircleHelp size={20} /></button><span className="topbar-divider" /><Avatar name={session.name} small /></div>
      </header>
      <main id="main-content">
        <div className="page-heading"><div><div className="eyebrow">ORGANIZATION ADMINISTRATION</div><h1>{page === 'overview' ? 'Your organization, in sync.' : page === 'people' ? 'Good people. Connected teams.' : page === 'departments' ? 'A place for every team.' : page === 'reporting' ? 'Department reporting' : page === 'access' ? 'The right access, for everyone.' : 'Every change, accounted for.'}</h1><p>{page === 'overview' ? 'A clear view of your people, departments and responsibilities.' : page === 'people' ? 'Manage accounts, department memberships and reporting relationships.' : page === 'departments' ? 'Build your organization around the teams that make it work.' : page === 'reporting' ? 'View the reporting chart and assign managers within a department.' : page === 'access' ? 'System access and department responsibilities are managed separately.' : 'A record of the organization updates made by administrators.'}</p></div>
          <div className="heading-actions">{page === 'people' && <button className="btn btn-secondary" onClick={exportPeople} disabled={loading || !filteredUsers.length}><ArrowDownToLine size={16} /> Export</button>}{['overview', 'people'].includes(page) && <button className="btn btn-primary" onClick={() => editUser(null)}><Plus size={18} /> Add person</button>}{page === 'departments' && <RouteLink className="btn btn-secondary" href="/admin/reporting"><Network size={18} /> Reporting Structure</RouteLink>}{page === 'departments' && <button className="btn btn-primary" onClick={() => editDepartment(null)}><Plus size={18} /> Add department</button>}</div>
        </div>
        {loadError && <div className="load-error"><ErrorBox error={loadError} /><button className="btn btn-secondary" onClick={() => refresh().catch(() => {})}>Retry loading</button></div>}
        {loading ? <div className="content-loading"><LoaderCircle className="spin" /><p>Loading your organization…</p></div> : <>
          {page === 'overview' && <><DashboardCharts title="Organization analytics" groupTitle="People by designation" records={data.users.map(u => ({ id: u.id, label: u.name, status: u.is_active ? 'Active' : 'Inactive', group: u.designation || 'Not set' }))} /><details className="reporting-details"><summary>View organization reporting chart</summary><OrganizationChart /></details>
            <section className="stats-grid" aria-label="Organization summary">
              {[['Total people', data.users.length, `${activeUsers.length} active accounts`, Users, 'mint'], ['Departments', activeDepartments.length, 'Active departments', Building2, 'sand'], ['Heads of function', hofIds.size, 'Across your departments', Network, 'lilac'], ['Needs attention', uncovered.length + unassigned.length, 'Organization setup items', ShieldCheck, 'blue']].map(([label, value, caption, Icon, tone]) => <article className="stat" key={label}><div className="stat-top"><span>{label}</span><span className={`stat-icon ${tone}`}><Icon size={19} /></span></div><strong>{value.toString().padStart(2, '0')}</strong><small><span className={`tiny-dot ${tone}`} />{caption}</small></article>)}
            </section>
            <div className="overview-grid"><section className="panel department-panel"><div className="panel-heading"><div><h2>Departments</h2><p>The teams behind your performance plans</p></div><button className="text-button" onClick={() => navigate('departments')}>View all <ArrowRight size={15} /></button></div>
              <DepartmentTable data={data} onEdit={editDepartment} limit={4} />
              <button className="panel-footer-action" onClick={() => editDepartment(null)}><Plus size={16} /> Create a department</button>
            </section><section className="setup-panel"><div className="setup-heading"><span className="setup-icon"><Sparkles size={20} /></span><Badge tone="mint">GETTING STARTED</Badge></div><h2>A well-connected<br />workspace starts here.</h2><p>Give every team a home and every person a clear responsibility.</p>
              <SetupItem done={activeDepartments.length > 0} label="Create your departments" detail={`${activeDepartments.length} departments ready`} onClick={() => navigate('departments')} />
              <SetupItem done={activeUsers.length > 1} label="Bring your people onboard" detail={`${activeUsers.length} active accounts`} onClick={() => navigate('people')} />
              <SetupItem done={activeDepartments.length > 0 && !uncovered.length} label="Assign department heads" detail={uncovered.length ? `${uncovered.length} department${uncovered.length > 1 ? 's' : ''} need a HOF` : 'Department leadership connected'} onClick={() => { navigate('people'); }} />
              <button className="setup-link" onClick={() => setModal({ kind: 'help' })}>Explore the setup guide <ArrowUpRight size={16} /></button>
            </section></div>
            <section className="panel people-panel"><div className="panel-heading"><div><h2>Your people <span className="count-label">{data.users.length}</span></h2><p>A shared directory, with access that fits each person</p></div><button className="text-button" onClick={() => navigate('people')}>View directory <ArrowRight size={15} /></button></div><PeopleTable users={data.users.slice(0, 5)} onEdit={editUser} compact /><div className="table-footer"><span>Showing {Math.min(data.users.length, 5)} of {data.users.length} people</span><span className="subtle-note"><ShieldCheck size={13} /> Administration access is restricted</span></div></section>
          </>}
          {page === 'people' && <section className="panel"><div className="directory-tools"><div className="directory-tabs"><button className={statusFilter === 'all' ? 'selected' : ''} onClick={() => setStatusFilter('all')}>All people <span>{data.users.length}</span></button><button className={statusFilter === 'active' ? 'selected' : ''} onClick={() => setStatusFilter('active')}>Active</button><button className={statusFilter === 'inactive' ? 'selected' : ''} onClick={() => setStatusFilter('inactive')}>Inactive</button></div><label className="filter-select"><SlidersHorizontal size={16} /><select aria-label="Filter by department" value={departmentFilter} onChange={e => setDepartmentFilter(e.target.value)}><option value="all">All departments</option>{data.departments.map(d => <option value={d.id} key={d.id}>{d.name}</option>)}</select><ChevronDown size={13} /></label></div><PaginatedPeople users={filteredUsers} onEdit={editUser} onRemove={user => setModal({ kind: 'remove', user })} currentUserId={session.id} filterKey={`${query}-${statusFilter}-${departmentFilter}`} /></section>}
          {page === 'departments' && <><div className="section-toolbar"><span>{activeDepartments.length} active departments <span className="separator-dot">·</span> {data.memberships.length} department memberships</span><Badge tone="mint">MULTIPLE DEPARTMENTS PER PERSON</Badge></div><section className="panel"><DepartmentTable data={data} onEdit={editDepartment} onRemove={department => setModal({ kind: 'remove', department })} /></section><div className="inline-info"><Network size={19} /><p>A person can lead or belong to more than one department. Add department responsibilities from their person profile.</p><button className="text-button" onClick={() => navigate('people')}>Manage people <ArrowRight size={15} /></button></div></>}
          {page === 'reporting' && <DepartmentReporting data={data} onSaved={savedReporting} />}
          {page === 'access' && <AccessView data={data} onManage={() => navigate('people')} />}
          {page === 'activity' && <section className="panel activity-panel"><div className="panel-heading"><div><h2>Organization activity</h2><p>The latest {data.activity.length} recorded updates</p></div><Badge tone="mint">AUDIT HISTORY</Badge></div><ActivityList data={data} /></section>}
          <footer className="workspace-footer"><span><span className="footer-dot" /> CPSTL Performance Management</span><span>Ceylon Petroleum Storage Terminals Limited</span></footer>
        </>}
      </main>
    </div>
    {modal?.kind === 'remove' && <RemoveRecordDialog record={modal.user || modal.department} department={!!modal.department}
      onClose={() => setModal(null)} onRemoved={saved} />}
    {modal && modal.kind !== 'remove' && <Modal title={modal.kind === 'user' ? (modal.user ? 'Edit person' : 'Add a person') : modal.kind === 'department' ? (modal.department ? 'Edit department' : 'Create a department') : 'Your organization setup guide'} subtitle={modal.kind === 'user' ? 'Connect a person to your organization and the right responsibilities.' : modal.kind === 'department' ? 'Give your team a name, a home and a clear identity.' : 'A few simple steps to build a connected organization.'} onClose={() => setModal(null)}>
      {modal.kind === 'user' ? <UserForm user={modal.user} data={data} session={session} onClose={() => setModal(null)} onSaved={saved} /> : modal.kind === 'department' ? <DepartmentForm department={modal.department} onClose={() => setModal(null)} onSaved={saved} /> : <HelpContent onClose={() => setModal(null)} />}
    </Modal>}
    {toast && <div className="toast" role="status"><CheckCircle2 size={18} /><span>{toast}</span><button className="icon-button" aria-label="Dismiss notification" onClick={() => setToast('')}><X size={15} /></button></div>}
  </div>;
}

function SetupItem({ done, label, detail, onClick }) {
  return <button className="setup-item" onClick={onClick}><span className={`setup-check ${done ? 'done' : ''}`}>{done ? <Check size={13} /> : <span />}</span><span><strong>{label}</strong><small>{detail}</small></span><ChevronRight size={15} /></button>;
}

function DepartmentTable({ data, onEdit, onRemove, limit }) {
  const departments = limit ? data.departments.filter(x => x.is_active).slice(0, limit) : data.departments;
  if (!departments.length) return <Empty icon={Building2} title="A home for your teams" text="Create your first department, then connect its people and leadership." action={<button className="btn btn-primary" onClick={() => onEdit(null)}><Plus size={16} /> Add department</button>} />;
  return <div className="table-scroll"><table className="department-table"><thead><tr><th>Department</th><th>Head of function</th><th>People</th><th>Status</th><th><span className="sr-only">Actions</span></th></tr></thead><tbody>{departments.map(d => {
    const heads = data.memberships.filter(m => idEquals(m.department_id, d.id) && m.responsibility === 'hof').map(m => userById(data, m.user_id)).filter(u => u?.is_active);
    return <tr key={d.id}><td><div className="department-name"><span className="department-icon" style={{ color: d.color, background: `${d.color}13` }}><Building2 size={20} /></span><span><strong>{d.name}</strong><small>{d.code}</small></span></div></td><td>{heads.length ? <div className="head-name"><Avatar name={heads[0].name} small index={d.id} /><span>{heads.map(h => h.name).join(', ')}</span></div> : <span className="unassigned">Not assigned</span>}</td><td><span className="people-count"><Users size={14} />{memberCount(data, d.id)}</span></td><td><Badge tone={d.is_active ? 'mint' : 'neutral'}>{d.is_active ? 'Active' : 'Archived'}</Badge></td><td><div style={{ display: 'flex', gap: 4 }}><button className="icon-button row-edit" title={`Edit ${d.name}`} aria-label={`Edit ${d.name}`} onClick={() => onEdit(d)}><Pencil size={15} /></button>
      {onRemove && <button className="icon-button danger" title={`Remove ${d.name}`} aria-label={`Remove ${d.name}`} onClick={() => onRemove(d)}><Trash2 size={15} /></button>}
    </div></td></tr>;
  })}</tbody></table></div>;
}

function PeopleTable({ users, onEdit, onRemove, currentUserId, compact = false }) {
  if (!users.length) return <Empty title="No people found" text="Try a different search or filter, or add a person to your organization." />;
  return <div className="table-scroll">
    <table className="people-table">
      <thead><tr><th>Name</th><th>Designation</th><th>System access</th><th>Status</th><th><span className="sr-only">Actions</span></th></tr></thead>
      <tbody>{users.map((user, index) => <tr key={user.id}>
        <td><div className="person-name">
          <Avatar name={user.name} index={index} />
          <span>
            <strong>{user.name}</strong>
            <small>{[!compact && user.employee_number, user.grade && `Grade ${user.grade}`].filter(Boolean).join(' · ')}</small>
            {!compact && <small className="person-email">{user.email.endsWith('@pending.cpstl.invalid') ? 'Work email pending' : user.email}</small>}
          </span>
        </div></td>
        <td>{user.designation || '—'}</td>
        <td><span className={`role-label ${user.system_role === 'super_admin' ? 'role-admin' : ''}`}>{user.system_role === 'super_admin' && <ShieldCheck size={13} />}{roles[user.system_role]}</span></td>
        <td><span className={`status-label ${!user.is_active ? 'inactive' : ''}`}><span />{user.is_active ? 'Active' : 'Inactive'}</span></td>
        <td><div style={{ display: 'flex', gap: 4 }}>
          <button className="icon-button row-edit" title={`Edit ${user.name}`} aria-label={`Edit ${user.name}`} onClick={() => onEdit(user)}><Pencil size={15} /></button>
          {onRemove && !idEquals(user.id, currentUserId) && <button className="icon-button danger" title={`Remove ${user.name}`} aria-label={`Remove ${user.name}`} onClick={() => onRemove(user)}><Trash2 size={15} /></button>}
        </div></td>
      </tr>)}</tbody>
    </table>
  </div>;
}

function PaginatedPeople({ users, onEdit, onRemove, currentUserId, filterKey }) {
  const [page, setPage] = useState(0);
  useEffect(() => setPage(0), [filterKey, users.length]);
  const pages = Math.max(1, Math.ceil(users.length / 8));
  const safePage = Math.min(page, pages - 1);
  return <><PeopleTable users={users.slice(safePage * 8, safePage * 8 + 8)} onEdit={onEdit} onRemove={onRemove} currentUserId={currentUserId} /><div className="table-footer"><span>{users.length ? `${safePage * 8 + 1}–${Math.min(safePage * 8 + 8, users.length)}` : '0'} of {users.length} people</span><div className="pagination"><button className="icon-button" aria-label="Previous page" disabled={safePage === 0} onClick={() => setPage(safePage - 1)}><ChevronLeft size={17} /></button><span>Page {safePage + 1} of {pages}</span><button className="icon-button" aria-label="Next page" disabled={safePage >= pages - 1} onClick={() => setPage(safePage + 1)}><ChevronRight size={17} /></button></div></div></>;
}

function RemoveRecordDialog({ record, department, onClose, onRemoved }) {
  const removing = useRef(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const label = department ? 'Remove department' : 'Remove person';
  async function remove() {
    if (removing.current) return;
    removing.current = true;
    setBusy(true);
    setError(null);
    try {
      const result = await request(`/admin/${department ? 'departments' : 'users'}/${record.id}`, { method: 'DELETE' });
      await onRemoved(result.message);
    } catch (err) {
      setError(err);
    } finally {
      removing.current = false;
      setBusy(false);
    }
  }
  return <Modal title={`${label}?`} subtitle={[record.name, department ? record.code : record.employee_number].filter(Boolean).join(' · ')}
    onClose={() => { if (!removing.current) onClose(); }}>
    <div className="modal-body">
      <ErrorBox error={error} />
      <p className="confirm-copy">{department
        ? 'This permanently removes the department. Move its people assignments first. Departments linked to saved work or reporting records cannot be removed.'
        : 'This permanently removes the account and its department memberships. To suspend sign-in and keep the account, set its status to Inactive from Edit person.'}</p>
    </div>
    <div className="modal-footer">
      <button className="btn btn-secondary" disabled={busy} onClick={onClose}>Cancel</button>
      <button className="btn btn-primary" disabled={busy} onClick={remove}>
        {busy ? <LoaderCircle className="spin" size={16} /> : <Trash2 size={16} />} {busy ? 'Removing…' : label}
      </button>
    </div>
  </Modal>;
}

function ActivityList({ data }) {
  if (!data.activity.length) return <Empty icon={Activity} title="Your history starts here" text="Changes to people and departments will appear here." />;
  const labels = { 'user.created': 'Added a person', 'user.updated': 'Updated a person', 'department.created': 'Created a department', 'department.updated': 'Updated a department', 'workspace.initialized': 'Initialized the workspace' };
  return <div className="activity-list">{data.activity.map(item => <div className="activity-item" key={item.id}><span className="activity-icon">{item.action.startsWith('department') ? <Building2 size={18} /> : item.action.startsWith('user') ? <Users size={18} /> : <CheckCircle2 size={18} />}</span><div><strong>{labels[item.action] || item.action}</strong><p>{item.subject}</p><small>By {item.actor_name || 'System'}</small></div><time>{new Date(item.created_at.replace(' ', 'T') + (item.created_at.includes('Z') ? '' : 'Z')).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</time></div>)}</div>;
}

function AccessView({ data, onManage }) {
  return <><section className="panel access-panel"><div className="panel-heading"><div><h2>System roles</h2><p>Platform access is separate from a person’s designation or department role.</p></div><button className="text-button" onClick={onManage}>Assign access <ArrowRight size={15} /></button></div><div className="role-list">{[
    ['super_admin', 'System administrator', 'Manage people, departments, organization relationships and audit history.', ShieldCheck],
    ['md', 'MD / executive viewer', 'Reserved for organization reporting. Read-only performance workspace comes in the next phase.', LayoutDashboard],
    ['plan_editor', 'Performance plan editor', 'Create personal plans, define KPA and KPI weights, set targets and publish plans for HOFs.', FolderTree],
    ['member', 'Team member', 'HOFs can view their own published plans. Manager and employee workspaces follow in a later phase.', Users],
  ].map(([id, label, description, Icon]) => <div className="role-row" key={id}><span className="role-icon"><Icon size={23} /></span><div><h3>{label}</h3><p>{description}</p></div><Badge tone={id === 'super_admin' ? 'mint' : 'neutral'}>{data.users.filter(u => u.system_role === id).length} people</Badge></div>)}</div></section><div className="inline-info"><Network size={21} /><p><strong>One person, several responsibilities.</strong> A CIO can be HOF of two departments, while each department keeps its own managers and reporting relationships.</p></div></>;
}

function UserForm({ user, data, session, onClose, onSaved }) {
  const [form, setForm] = useState(() => user ? {
    ...user,
    password: '',
    memberships: user.system_role === 'md' ? [] : memberOf(data, user.id).map(membership => ({
      department_id: String(membership.department_id),
      responsibility_title: membership.responsibility_title || responsibilities[membership.responsibility] || '',
    })),
  } : { ...initialUser, memberships: [] });
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const set = (key, value) => setForm(previous => ({
    ...previous, [key]: value, ...(key === 'system_role' && value === 'md' ? { memberships: [] } : {}),
  }));
  const updateMembership = (index, changes) => setForm(previous => ({
    ...previous,
    memberships: previous.memberships.map((membership, i) => i === index ? { ...membership, ...changes } : membership),
  }));
  const isSelf = user?.id === session.id;
  const activeDepartments = data.departments.filter(department => department.is_active);

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await request(user ? `/admin/users/${user.id}` : '/admin/users', {
        method: user ? 'PUT' : 'POST',
        body: {
          ...form,
          memberships: form.memberships.map(membership => ({
            department_id: Number(membership.department_id),
            responsibility_title: membership.responsibility_title.trim(),
          })),
        },
      });
      onSaved(user ? 'Person updated successfully.' : 'Person added to your organization.');
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return <form onSubmit={submit}>
    <div className="modal-body">
      <ErrorBox error={error} />
      <div className="form-grid">
        {form.system_role !== 'md' && <div className="person-departments">
          <div className="person-department-actions">
            <span>Departments</span>
            <button className="btn btn-secondary btn-small" type="button"
              disabled={form.memberships.length >= activeDepartments.length}
              onClick={() => set('memberships', [...form.memberships, { department_id: '', responsibility_title: '' }])}>
              <Plus size={15} />Add department
            </button>
          </div>
          <datalist id="department-responsibility-titles">{responsibilityTitles.map(title => <option key={title} value={title} />)}</datalist>
          {!form.memberships.length && <div className="membership-empty"><Building2 size={20} /><span>Add the departments this person belongs to.</span></div>}
          {form.memberships.map((membership, index) => <div className="membership-editor" key={index}>
            <div className="membership-editor-top">
              <span>Department {index + 1}</span>
              <button type="button" className="icon-button danger" aria-label={`Remove department ${index + 1}`}
                onClick={() => set('memberships', form.memberships.filter((_, i) => i !== index))}><Trash2 size={16} /></button>
            </div>
            <div className="form-grid">
              <Field label="Department" required>
                <select value={membership.department_id} required onChange={event => updateMembership(index, { department_id: event.target.value, responsibility_title: '' })}>
                  <option value="">Select department</option>
                  {activeDepartments.filter(department => !form.memberships.some((other, i) => i !== index && idEquals(other.department_id, department.id)))
                    .map(department => <option key={department.id} value={department.id}>{department.name}</option>)}
                </select>
              </Field>
              <Field label="Responsibility" required hint="Type the responsibility used in this department.">
                <input list="department-responsibility-titles" maxLength={120} required value={membership.responsibility_title}
                  onChange={event => updateMembership(index, { responsibility_title: event.target.value })}
                  placeholder="e.g. CIO, Head of Data Center, Systems Engineer" />
              </Field>
            </div>
          </div>)}
        </div>}
        <Field label="Full name" required><input value={form.name} onChange={event => set('name', event.target.value)} maxLength={120} placeholder="e.g. Amali Perera" required /></Field>
        <Field label="Employee / EPF number" required><input value={form.employee_number || ''} onChange={event => set('employee_number', event.target.value.trim())} maxLength={40} placeholder="e.g. EMP012" required /></Field>
        <Field label="Work email" required hint={user?.email.endsWith('@pending.cpstl.invalid') ? 'Enter the work email and set an initial password before activating.' : undefined}>
          <input type="email" value={form.email} onChange={event => set('email', event.target.value.trim().toLowerCase())} maxLength={180} placeholder="name@organization.com" required />
        </Field>
        <Field label="Designation" required><input value={form.designation || ''} onChange={event => set('designation', event.target.value)} placeholder="e.g. CIO, DGM, Systems Executive" maxLength={120} required /></Field>
        <Field label="Grade"><input value={form.grade || ''} onChange={event => set('grade', event.target.value)} placeholder="e.g. A5" maxLength={30} /></Field>
        <Field label="System access" required><select disabled={isSelf} value={form.system_role} onChange={event => set('system_role', event.target.value)}>{Object.entries(roles).map(([id, label]) => <option value={id} key={id}>{label}</option>)}</select></Field>
        <Field label="Account status"><select disabled={isSelf} value={String(form.is_active)} onChange={event => set('is_active', event.target.value === 'true')}><option value="true">Active</option><option value="false">Inactive</option></select></Field>
        <Field label={user ? 'New password (optional)' : 'Initial password'} required={!user}
          hint={user ? 'At least 6 characters when changing the password. This signs out existing sessions.' : 'At least 6 characters.'}>
          <input type="password" autoComplete="new-password" minLength={6} maxLength={128} value={form.password} onChange={event => set('password', event.target.value)} required={!user} />
        </Field>
      </div>
      <div className="form-note"><Network size={16} /><span>{form.system_role === 'md' ? 'The Managing Director has company-wide dashboard access.' : 'Set reporting connections and department workflow access in Department reporting.'}</span></div>
    </div>
    <div className="modal-footer">
      <button className="btn btn-secondary" type="button" onClick={onClose} disabled={busy}>Cancel</button>
      <button className="btn btn-primary" disabled={busy}>{busy ? <LoaderCircle size={16} className="spin" /> : <Check size={17} />}{user ? 'Save changes' : 'Add person'}</button>
    </div>
  </form>;
}

function DepartmentForm({ department, onClose, onSaved }) {
  const [form, setForm] = useState(department ? { ...department } : { ...initialDepartment });
  const [busy, setBusy] = useState(false), [error, setError] = useState(null);
  const set = (key, value) => setForm(f => ({ ...f, [key]: value }));
  async function submit(e) {
    e.preventDefault(); setBusy(true); setError(null);
    try { await request(department ? `/admin/departments/${department.id}` : '/admin/departments', { method: department ? 'PUT' : 'POST', body: form }); onSaved(department ? 'Department updated successfully.' : 'Department created. You can now add its people.'); }
    catch (err) { setError(err); }
    finally { setBusy(false); }
  }
  return <form onSubmit={submit}><div className="modal-body"><ErrorBox error={error} /><div className="form-grid"><Field label="Department name" required><input value={form.name} onChange={e => set('name', e.target.value)} placeholder="e.g. Human Resources" maxLength={120} required /></Field><Field label="Department code" required hint="A unique short code. Letters, numbers and hyphens."><input value={form.code} onChange={e => set('code', e.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, ''))} maxLength={20} placeholder="e.g. HR" required /></Field></div><Field label="Description"><textarea rows={3} value={form.description || ''} onChange={e => set('description', e.target.value)} maxLength={500} placeholder="What does this team look after?" /></Field><div className="form-grid"><Field label="Department color"><div className="color-options">{colors.map(color => <button className={form.color === color ? 'color-option chosen' : 'color-option'} key={color} type="button" style={{ background: color }} onClick={() => set('color', color)} aria-label={`Choose color ${color}`} aria-pressed={form.color === color}>{form.color === color && <Check size={17} />}</button>)}</div></Field><Field label="Status"><select value={String(form.is_active)} onChange={e => set('is_active', e.target.value === 'true')}><option value="true">Active</option><option value="false">Archived</option></select></Field></div><div className="form-note"><Network size={17} /><span>Assign the HOF, managers and employees from their person profiles. A department must have no memberships before it can be archived.</span></div></div><div className="modal-footer"><button className="btn btn-secondary" type="button" onClick={onClose} disabled={busy}>Cancel</button><button className="btn btn-primary" disabled={busy}>{busy ? <LoaderCircle className="spin" size={16} /> : <Check size={17} />}{department ? 'Save changes' : 'Create department'}</button></div></form>;
}

function HelpContent({ onClose }) {
  return <><div className="modal-body help-content">{[
    ['01', 'Create departments', 'Add a department name and unique code. You can add more departments whenever your organization grows.'],
    ['02', 'Add your people', 'Create accounts with an employee number, designation and system role. Inactive accounts cannot sign in.'],
    ['03', 'Connect responsibilities', 'Add department memberships and choose HOF, manager or employee. A person can hold different responsibilities across departments.'],
    ['04', 'Set reporting relationships', 'Select one or more reporting managers in the same department. Circular reporting and self-reporting are prevented.'],
  ].map(([number, title, text]) => <div className="help-step" key={number}><span>{number}</span><div><h3>{title}</h3><p>{text}</p></div></div>)}<div className="form-note"><FolderTree size={19} /><span>Open Performance plans to create and publish HOF plans. Excel import and delegated work are later milestones.</span></div></div><div className="modal-footer"><button className="btn btn-primary" onClick={onClose}>Got it <Check size={16} /></button></div></>;
}


