import { same, pct, statuses, editable } from './deliveryFormat.js';
import { Progress, TaskBadge } from './DeliveryUi.jsx';
import TaskCard from './TaskCard.jsx';
import KpiResponsibilities from './KpiResponsibilities.jsx';
import BreakdownForm from './BreakdownForm.jsx';
import TaskAction from './TaskAction.jsx';
import SubmissionHistory from './SubmissionHistory.jsx';
import PersonalPlanWork from './PersonalPlanWork.jsx';
import './personal-plan-work.css';
import DashboardCharts from '../../components/DashboardCharts.jsx';
import OrganizationChart from '../organization/OrganizationChart.jsx';
import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, ArrowRight, CheckCircle2, ChevronRight, ClipboardList, Eye, FileCheck2, LayoutDashboard, LoaderCircle, LogOut, Menu, Monitor, Network, Plus, Send, Target, UserCheck, Users, X } from 'lucide-react';
import { request } from '../../services/api.js';
import { Avatar, Brand, Empty, ErrorBox, Modal, RouteLink } from '../../components/ui.jsx';
import '../planning/planning.css';
import './delivery.css';
import PersonalAllocationWorkspace from '../allocations/PersonalAllocationWorkspace.jsx';
import MonthlyKpiWorkspace from '../kpi/MonthlyKpiWorkspace.jsx';
import { defaultDeliveryPage, workspacePath } from '../../app/workspaceRoutes.js';
import '../allocations/allocations.css';
import WorkFocus from '../../components/WorkFocus.jsx';

const empty = { managed: [], tasks: [], reviews: [], people: [], employees: [], departments: [], submissions: [], roles: [], delegated_kpis: [], planned_work: [], plan_reviewers: [] };


export default function DeliveryWorkspace({ session, onSession, page, onPage, onHof, notificationTarget, embedded = false }) {
  const allocationGuard = useRef(null);
  const [data, setData] = useState(empty), [loading, setLoading] = useState(true), [error, setError] = useState(null);
  const [mobile, setMobile] = useState(false), [year, setYear] = useState('all'), [query, setQuery] = useState(''), [status, setStatus] = useState('all');
  const [modal, setModal] = useState(null), [toast, setToast] = useState('');
  async function refresh() {
    try { const result = await request('/delivery/workspace'); setData(result); setError(null); return result; }
    catch (err) { setError(err); if (err.status === 401) onSession(null); }
    finally { setLoading(false); }
  }
  useEffect(() => { refresh(); }, []);
  useEffect(() => {
    if (!notificationTarget || notificationTarget.workspace !== 'delivery') return;
    setQuery(notificationTarget.task_id || notificationTarget.assignment_id || notificationTarget.delegation_id ? '' : notificationTarget.search || ''); setYear('all'); setStatus('all'); setModal(null); setMobile(false);
    let active = true;
    refresh().then(result => {
      if (!active || !result) return;
      if (notificationTarget.task_id) {
        const task = [...result.reviews, ...result.tasks].find(row => same(row.id, notificationTarget.task_id));
        if (!task) { setToast('This work has changed or is no longer available in your current scope.'); return; }
        const latest = result.submissions.filter(row => same(row.task_id, task.id))[0];
        const pending = notificationTarget.page === 'reviews' && task.status === 'submitted' && latest?.decision === 'pending' && (!notificationTarget.submission_id || same(latest.id, notificationTarget.submission_id));
        setModal({ kind: pending ? 'review' : 'history', task });
      } else if (notificationTarget.assignment_id) {
        const parent = result.managed.find(row => same(row.id, notificationTarget.assignment_id));
        if (parent) setModal({ kind: 'breakdown', parent });
        else setToast('This assignment is no longer available in your current scope.');
      } else if (notificationTarget.delegation_id) {
        const kpi = result.delegated_kpis.find(row => same(row.delegation.id, notificationTarget.delegation_id));
        if (kpi) setQuery(kpi.kpi_title);
        else setToast('This KPI is no longer available in your current scope.');
      }
    });
    return () => { active = false; };
  }, [notificationTarget]);
  useEffect(() => { if (!toast) return; const timer = setTimeout(() => setToast(''), 5000); return () => clearTimeout(timer); }, [toast]);
  async function saved(message) { setModal(null); await refresh(); setToast(message); }
  async function logout() { try { await request('/logout', { method: 'POST' }); onSession(null); } catch (err) { setError(err); } }
  function navigate(key) { const go = () => { onPage(key); setQuery(''); setStatus('all'); setMobile(false); }; if (allocationGuard.current) allocationGuard.current(go); else go(); }
  const manager = data.managed.length > 0 || data.employees.length > 0 || data.roles.includes('manager') || session.workspaces?.includes('manager');
  const person = id => same(id, session.id) ? `${session.name} · me` : data.people.find(user => same(user.id, id))?.name || data.employees.find(user => same(user.id, id))?.name || 'Team member';
  const department = id => data.departments.find(item => same(item.id, id))?.name || 'Department';
  const match = row => (year === 'all' || String((row.parent || row).year) === year) && [row.title, row.activity, row.kpi_title, row.kpa_title, row.parent?.activity, person(row.assignee_id)].some(value => String(value || '').toLowerCase().includes(query.toLowerCase()));
  const activities = data.managed.filter(match);
  const tasks = data.tasks.filter(task => match(task) && (status === 'all' || task.status === status));
  const reviews = data.reviews.filter(match);
  const teamTasks = data.managed.flatMap(parent => parent.tasks.map(task => ({ ...task, parent }))).filter(match);
  const years = [...new Set([...data.managed.map(row => row.year), ...data.delegated_kpis.map(row => row.year), ...data.tasks.map(row => row.parent.year), ...data.reviews.map(row => row.parent.year), ...(data.planned_work || []).map(row => row.year)].filter(Boolean))].sort((a, b) => b - a);
  const workPages = ['tasks', 'activities', 'kpis'];
  const teamPages = ['reviews', 'team'];
  const showTeam = manager || onHof || data.reviews.length > 0 || teamPages.includes(page);
  const navigation = [
    ['overview', 'Overview', LayoutDashboard, ['overview']],
    ['tasks', 'My work', ClipboardList, workPages],
    ...(showTeam ? [['reviews', 'Team & reviews', Users, teamPages]] : []),
  ];
  const workTabs = [['tasks', 'My tasks', UserCheck, tasks.length], ...(manager || page === 'activities' ? [['activities', 'Assigned activities', ClipboardList, activities.length]] : []), ...(manager || data.delegated_kpis.length || page === 'kpis' ? [['kpis', 'Linked KPIs', Target, data.delegated_kpis.filter(match).length]] : [])];
  const teamTabs = [['reviews', 'Needs my review', CheckCircle2, reviews.length], ...(manager || page === 'team' ? [['team', 'Team progress', Users, teamTasks.length]] : [])];
  const sectionTabs = workPages.includes(page) ? workTabs : teamPages.includes(page) ? teamTabs : [];
  const sectionDescription = page === 'overview' ? 'Your progress, next actions and team connections in one place.' : page === 'tasks' ? 'Update your progress, submit completed work or divide work with your team.' : page === 'activities' ? 'Turn assigned activities into clear tasks with agreed weights and owners.' : page === 'kpis' ? 'See the KPIs delegated to you and the activities that contribute to them.' : page === 'team' ? 'Track owners, progress and approval status across your assigned activities.' : 'Review submitted evidence, approve completed work or request changes.';
  const personalYear = year === 'all' ? (years.includes(new Date().getFullYear()) ? new Date().getFullYear() : years[0]) : Number(year);
  const personalScore = data.personal_scores?.[personalYear];
  const heading = embedded && page === 'reviews' ? 'Task reviews' : page === 'overview' ? 'Your delivery overview.' : workPages.includes(page) ? 'My work' : 'Team & reviews';
  const submissionsFor = task => data.submissions.filter(row => same(row.task_id, task.id));
  const focusItems = [...reviews.map(task => ({ id: `review-${task.id}`, title: task.title, meta: `Review requested · ${person(task.assignee_id)} · ${task.parent.activity}`, due: task.due_date || task.parent.due_date, status: 'Awaiting your review', priority: 1, onOpen: () => setModal({ kind: 'review', task }) })), ...tasks.filter(task => editable(task)).map(task => ({ id: `task-${task.id}`, title: task.title, meta: `${department(task.parent.department_id)} · ${task.self_confirm ? 'HOF confirmation' : `Reviewer: ${person(task.reviewer_id)}`}`, due: task.due_date || task.parent.due_date, status: task.status === 'needs_changes' ? 'Changes requested' : `${Number(task.reported_progress)}% reported`, priority: task.status === 'needs_changes' ? 0 : undefined, onOpen: () => setModal({ kind: task.status === 'needs_changes' ? 'history' : 'progress', task }) }))];


  const Content = embedded ? 'section' : 'main';
  return <div className={embedded ? 'delivery-embedded delivery-shell' : 'app-shell planning-shell delivery-shell'}>
    {!embedded && mobile && <button className="nav-scrim" aria-label="Close navigation" onClick={() => setMobile(false)} />}
    {!embedded && <>
    <aside className={`sidebar ${mobile ? 'sidebar-open' : ''}`}><RouteLink className="brand" href={workspacePath('delivery', defaultDeliveryPage(session))} onNavigate={() => navigate(defaultDeliveryPage(session))}><Brand /></RouteLink><div className="workspace-label"><span className="workspace-dot" /> DELIVERY WORKSPACE</div><div className="nav-caption">WORKSPACE</div><nav className="workspace-primary-nav" aria-label="Task delivery">{navigation.map(([key, label, Icon, pages]) => <RouteLink key={key} href={workspacePath('delivery', key)} aria-current={pages.includes(page) ? 'page' : undefined} className={`nav-item ${pages.includes(page) ? 'nav-active' : ''}`} onNavigate={() => navigate(key)}><Icon size={20} /><span>{label}<small>{key === 'overview' ? 'Progress & priorities' : key === 'tasks' ? 'Tasks, activities & KPIs' : 'Approvals & team progress'}</small></span>{key === 'reviews' && data.reviews.length > 0 && <span className="nav-count">{data.reviews.length}</span>}</RouteLink>)}</nav>
      <div className="workspace-nav-divider" /><div className="nav-caption">PERFORMANCE & REPORTS</div><nav aria-label="Performance and reports"><RouteLink className={`nav-item workspace-utility-link ${page === 'personal' ? 'nav-active' : ''}`} aria-current={page === 'personal' ? 'page' : undefined} href={workspacePath('delivery', 'personal')} onNavigate={() => navigate('personal')}><Target size={18} /><span>My allocation</span></RouteLink>{!onHof && session.can_view_plans && <RouteLink className="nav-item workspace-utility-link" href="/planning/plans"><ClipboardList size={18} /><span>My published plans</span></RouteLink>}<RouteLink className={`nav-item workspace-utility-link ${page === 'results' ? 'nav-active' : ''}`} aria-current={page === 'results' ? 'page' : undefined} href="/delivery/results" onNavigate={() => navigate('results')}><Target size={18} /><span>Monthly KPI Results</span></RouteLink>{session.workspaces?.some(role => ['hof', 'manager'].includes(role)) && <a className="nav-item workspace-utility-link" href="/display" target="_blank" rel="noopener noreferrer"><Monitor size={18} /><span>TV display</span><ArrowRight size={14} /></a>}</nav>
      {onHof && <><div className="workspace-nav-divider" /><RouteLink className="nav-item workspace-utility-link" href="/hof/overview" onNavigate={onHof}><ArrowLeft size={18} /><span>HOF dashboard</span></RouteLink></>}<div className="sidebar-bottom"><div className="sidebar-tip"><span className="tip-icon"><CheckCircle2 size={23} /></span><strong>Work. Review. Deliver.</strong><p>Manage your tasks in My work. Approve team submissions in Team & reviews.</p></div><div className="sidebar-user"><Avatar name={session.name} small /><span><strong>{session.name}</strong><small>{manager ? 'Manager' : onHof ? 'Head of function' : 'Employee'}</small></span><button className="icon-button" aria-label="Sign out" onClick={logout}><LogOut size={17} /></button></div></div></aside>
    </>}
    <div className={embedded ? 'delivery-embedded-content' : 'workspace'}>{!embedded && <header className="topbar"><div className="breadcrumb"><button className="icon-button mobile-menu" aria-label="Open navigation" onClick={() => setMobile(true)}><Menu size={22} /></button><span>CPSTL</span><ChevronRight size={14} /><strong>Task delivery</strong></div><div className="topbar-right"><button className="text-button" onClick={refresh}>Refresh workspace</button><Avatar name={session.name} small /></div></header>}<Content className={embedded ? 'delivery-embedded-main' : undefined}>
      {['personal', 'results'].includes(page) ? (page === 'personal' ? <PersonalAllocationWorkspace session={session} embedded navigationGuard={allocationGuard} onBack={() => navigate(defaultDeliveryPage(session))} /> : <MonthlyKpiWorkspace session={session} embedded notificationTarget={notificationTarget} />) : <>
      <div className="page-heading delivery-page-heading"><div><div className="eyebrow">TASK DELIVERY & REVIEW</div><h1>{heading}</h1><p>{sectionDescription}</p></div>{embedded && <button className="btn btn-secondary" disabled={loading} onClick={refresh}>Refresh reviews</button>}</div>
      {error && <div className="load-error"><ErrorBox error={error} /><button className="btn btn-secondary" onClick={refresh}>Retry</button></div>}
      {loading ? <div className="content-loading"><LoaderCircle className="spin" /><p>Loading your delivery workspace…</p></div> : !error && <>
        {!embedded && sectionTabs.length > 0 && <nav className="workspace-section-tabs" aria-label={workPages.includes(page) ? 'My work views' : 'Team and review views'}>{sectionTabs.map(([key, label, Icon, count]) => <RouteLink key={key} href={workspacePath('delivery', key)} aria-current={page === key ? 'page' : undefined} className={page === key ? 'is-active' : ''} onNavigate={() => navigate(key)}><Icon size={17} /><span>{label}</span><small>{count}</small></RouteLink>)}</nav>}
        {personalScore && ['overview', 'tasks'].includes(page) && <section className="delivery-personal-summary" aria-label="Personal performance">
          <article className="delivery-completion-card"><div className="delivery-summary-label"><span><Target size={17} />MY OVERALL COMPLETION</span><span className="delivery-year-chip">{personalYear}</span></div><div className="delivery-summary-score"><strong>{personalScore.current ? Number(Number(personalScore.confirmed_completion).toFixed(2)) : '—'}{personalScore.current && <span>%</span>}</strong><span>{personalScore.current ? 'Confirmed work completion' : 'Allocation needs attention'}</span></div><div className="delivery-summary-track"><span style={{ width: `${personalScore.current ? Math.min(100, Math.max(0, Number(personalScore.confirmed_completion))) : 0}%` }} /></div><p><CheckCircle2 size={14} />Based on confirmed work and agreed weights</p></article>
          <article className="delivery-summary-detail"><span className="delivery-summary-icon"><ClipboardList size={21} /></span><small>MY RESPONSIBILITIES</small><strong>{personalScore.responsibility_count}</strong><p>Work included in my personal allocation</p></article>
          <article className="delivery-summary-detail delivery-summary-allocation"><span className="delivery-summary-icon"><Target size={21} /></span><small>PERSONAL ALLOCATION</small><strong>100<span>%</span></strong><p>Work weights normalize to 100%. This is separate from completion.</p></article>
        </section>}<div className="delivery-filters"><span className="delivery-filter-label"><ClipboardList size={16} />Find your work</span><input aria-label="Search delivery work" placeholder="Search tasks, activities or people…" value={query} onChange={e => setQuery(e.target.value)} /><select aria-label="Delivery year" value={year} onChange={e => setYear(e.target.value)}><option value="all">All years</option>{years.map(value => <option key={value}>{value}</option>)}</select>{page === 'tasks' && <select aria-label="Task status" value={status} onChange={e => setStatus(e.target.value)}><option value="all">All task statuses</option>{Object.entries(statuses).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select>}</div>
        {page === 'overview' && <WorkFocus items={focusItems} subtitle={"Review requests, changes needed and your next delivery deadlines"} />}{page === 'overview' && <><DashboardCharts showProgressValues title={manager ? 'Manager delivery analytics' : 'My task analytics'} records={manager ? activities.map(r => ({ id: r.id, label: r.activity, group: department(r.department_id), status: !r.valid ? 'Review assignment' : Number(r.confirmed_progress) === 100 ? 'Approved' : Number(r.reported_progress) > 0 ? 'In progress' : 'Assigned', approved: r.valid ? Number(r.confirmed_progress) : 0, reported: Number(r.reported_progress) })) : tasks.map(t => ({ id: t.id, label: t.title, group: department(t.parent.department_id), status: statuses[t.status], reported: Number(t.reported_progress), approved: Number(t.confirmed_progress || 0) }))} /><details className="reporting-details"><summary><Network size={19} /><span><strong>My reporting connections</strong><small>Explore your team, designations and reporting managers</small></span><ChevronRight size={18} /></summary><OrganizationChart /></details></>}
        {page === 'tasks' && <PersonalPlanWork rows={(data.planned_work || []).filter(match)} reviewers={data.plan_reviewers || []} onSaved={saved} />}
        {page === 'overview' ? <DeliveryShortcuts manager={manager} tasks={tasks} activities={activities} reviews={reviews} showTeam={showTeam} onNavigate={navigate} /> : page === 'kpis' ? <KpiResponsibilities rows={data.delegated_kpis.filter(match)} person={person} department={department} onWork={navigate} onPersonal={() => navigate('personal')} /> : page === 'activities' ? <section className="panel"><div className="panel-heading"><div><h2>Assigned activities <span className="count-label">{activities.length}</span></h2><p>Each activity has its own 100% task allocation</p></div></div>{!activities.length ? <Empty icon={ClipboardList} title="No assigned activities in this scope" text="Your HOF can assign published activities to you. They will appear here with their agreed weight and deadline." /> : activities.map(parent => <article className="delivery-parent" key={parent.id}><div className="delivery-parent-top"><div><small>{department(parent.department_id)} · {parent.year || 'Previous plan'} · {parent.activity_code}</small><h2>{parent.activity}</h2><p>{parent.kpa_title} → {parent.kpi_title}</p><div className="delivery-parent-facts"><span>HOF: {person(parent.owner_id)}</span><span>{pct(parent.personal_weight)} of HOF plan</span><span>{parent.due_date ? `Due ${parent.due_date}` : 'No assignment deadline'}</span></div></div><button className="btn btn-secondary" disabled={!parent.valid} onClick={() => setModal({ kind: 'breakdown', parent })}>{parent.breakdown_locked ? <Eye size={16} /> : <Plus size={16} />}{parent.breakdown_locked ? 'View tasks' : parent.tasks.length ? 'Edit task breakdown' : 'Create task breakdown'}</button></div>{parent.instructions && <p className="delivery-instructions">{parent.instructions}</p>}{!parent.valid ? <div className="delivery-warning">This activity or reporting relationship changed. Ask the HOF to review its assignment before continuing.</div> : <><div className="delivery-parent-progress"><Progress label="Reported" value={parent.reported_progress} /><Progress label="Approved completion" value={parent.confirmed_progress} approved /><span>{parent.tasks.length} task{parent.tasks.length === 1 ? '' : 's'} · {parent.allocation_ready ? '100% allocated' : 'Breakdown needed'}</span></div>{parent.tasks.length > 0 && <div className="delivery-task-preview">{parent.tasks.map(task => <button key={task.id} onClick={() => setModal({ kind: 'history', task: { ...task, parent } })}><span>{task.title}<small>{person(task.assignee_id)}</small></span><strong>{pct(task.weight)}</strong><TaskBadge task={task} /></button>)}</div>}</>}</article>)}</section> : page === 'team' ? <section className="panel"><div className="panel-heading"><div><h2>Employee tasks <span className="count-label">{teamTasks.length}</span></h2><p>Weights are relative to each parent activity</p></div></div>{!teamTasks.length ? <Empty icon={Users} title="No employee tasks yet" text="Create a 100% task breakdown from an assigned activity first." /> : <div className="table-scroll"><table className="delivery-team-table"><thead><tr><th>Task / activity</th><th>Owner</th><th>Task weight</th><th>Reported</th><th>Status</th></tr></thead><tbody>{teamTasks.map(task => <tr key={task.id}><td><button className="text-button" onClick={() => setModal({ kind: 'history', task })}>{task.title}</button><small>{task.parent.activity}</small></td><td>{person(task.assignee_id)}</td><td>{pct(task.weight)}</td><td>{Number(task.reported_progress)}%</td><td><TaskBadge task={task} />{!task.assignee_eligible && <small>Reporting link changed</small>}</td></tr>)}</tbody></table></div>}</section> : <section className="panel"><div className="panel-heading"><div><h2>{page === 'reviews' ? 'Awaiting your review' : 'Tasks assigned to me'} <span className="count-label">{page === 'reviews' ? reviews.length : tasks.length}</span></h2><p>{page === 'reviews' ? 'Review work assigned by you and confirm its completion' : 'Only approved tasks contribute to confirmed activity completion'}</p></div></div>{!(page === 'reviews' ? reviews : tasks).length ? <Empty icon={page === 'reviews' ? CheckCircle2 : ClipboardList} title={page === 'reviews' ? 'No submissions awaiting your review' : 'No tasks in this scope'} text={page === 'reviews' ? 'Completed work appears here after the task owner submits it.' : 'Your manager will allocate tasks to you from their assigned activities.'} /> : (page === 'reviews' ? reviews : tasks).map(task => <TaskCard key={task.id} task={task} reviewing={page === 'reviews'} person={person} department={department} submissions={submissionsFor(task)} onAction={kind => {
          if (kind === 'breakdown') setModal({ kind, parent: { ...task.parent, id: task.id, version: task.version, activity: task.title, task_branch: true, tasks: task.children, breakdown_locked: task.breakdown_locked } });
          else setModal({ kind, task });
        }} />)}</section>}
        <div className="form-note"><CheckCircle2 size={18} /><span><strong>Approval drives confirmed completion.</strong> Confirmed child work adds its assigned weight to each parent. HOF own work is confirmed by its owner. Other work requires its assigning reviewer’s approval. This measures work completion, separate from KPI outcome scoring.</span></div>
      </>}
      </>}
      {!embedded && <footer className="workspace-footer"><span>CPSTL Performance Management</span><span>Ceylon Petroleum Storage Terminals Limited</span></footer>}
    </Content></div>
    {modal && <Modal className="delivery-modal" icon={modal.kind === 'breakdown' ? Users : modal.kind === 'progress' ? Target : modal.kind === 'submit' ? Send : modal.kind === 'review' ? FileCheck2 : ClipboardList} title={modal.kind === 'breakdown' ? 'Activity task breakdown' : modal.kind === 'progress' ? 'Update reported progress' : modal.kind === 'submit' ? 'Submit completed work' : modal.kind === 'review' ? 'Review task submission' : 'Evidence & review history'} subtitle={modal.parent?.activity || modal.task?.title} onClose={() => setModal(null)}>{modal.kind === 'breakdown' ? <BreakdownForm parent={modal.parent} data={data} session={session} onClose={() => setModal(null)} onSaved={saved} /> : modal.kind === 'history' ? <><SubmissionHistory rows={submissionsFor(modal.task)} person={person} /><div className="modal-footer"><button className="btn btn-secondary" onClick={() => setModal(null)}>Close</button></div></> : <TaskAction kind={modal.kind} task={modal.task} submissions={submissionsFor(modal.task)} person={person} onClose={() => setModal(null)} onSaved={saved} />}</Modal>}
    {toast && <div className="toast" role="status"><CheckCircle2 size={18} /><span>{toast}</span><button className="icon-button" aria-label="Dismiss notification" onClick={() => setToast('')}><X size={15} /></button></div>}
  </div>;
}

function DeliveryShortcuts({ manager, tasks, activities, reviews, showTeam, onNavigate }) {
  const items = [
    ['tasks', 'My work', `${tasks.length} task${tasks.length === 1 ? '' : 's'} in this view`, 'Update progress and submit completed work.', ClipboardList],
    ...(manager ? [['activities', 'Assigned activities', `${activities.length} activit${activities.length === 1 ? 'y' : 'ies'} in this view`, 'Set task owners, deadlines and weights.', Target]] : []),
    ...(showTeam ? [['reviews', 'Team & reviews', `${reviews.length} awaiting review`, 'Review evidence and follow your team’s delivery.', Users]] : []),
  ];
  return <section className="workspace-shortcuts" aria-label="Continue your work">{items.map(([key, title, count, description, Icon]) => <RouteLink key={key} href={workspacePath('delivery', key)} onNavigate={() => onNavigate(key)}><span className="workspace-shortcut-icon"><Icon size={22} /></span><div><small>{count}</small><h3>{title}</h3><p>{description}</p></div><ArrowRight size={18} /></RouteLink>)}</section>;
}




