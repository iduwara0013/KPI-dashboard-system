import ExtraWorkWorkspace from '../delivery/ExtraWorkWorkspace.jsx';
import Timeliness from '../../components/Timeliness.jsx';
import DashboardCharts from '../../components/DashboardCharts.jsx';
import OrganizationChart from '../organization/OrganizationChart.jsx';
import { useEffect, useState } from 'react';
import { Activity, ArrowRight, ArrowUpRight, BarChart3, Building2, CheckCircle2, Clock, Eye, LayoutDashboard, LoaderCircle, LogOut, Menu, Monitor, RefreshCw, Search, ShieldCheck, Target, Users } from 'lucide-react';
import { request } from '../../services/api.js';
import { InfoDetails, Avatar, Badge, Brand, Empty, ErrorBox, Modal, RouteLink } from '../../components/ui.jsx';
import './md.css';
import './md-navigation.css';
import { deliveryStats as stats } from './mdMetrics.js';
import WorkFocus from '../../components/WorkFocus.jsx';
import { workspacePath } from '../../app/workspaceRoutes.js';

const same = (a, b) => String(a) === String(b);
const pct = n => `${Number(Number(n).toFixed(2))}%`;

export default function MdWorkspace({ session, onSession, page, onPage, notificationTarget }) {
  const [data, setData] = useState(null), [year, setYear] = useState(String(new Date().getFullYear())), [department, setDepartment] = useState('all');
  const [query, setQuery] = useState(''), [filter, setFilter] = useState('all'), [error, setError] = useState(null), [loading, setLoading] = useState(true), [mobile, setMobile] = useState(false), [detail, setDetail] = useState(null);
  async function refresh() {
    setLoading(true); setError(null);
    try { setData(await request(`/md/workspace?year=${year}`)); } catch (err) { setError(err); } finally { setLoading(false); }
  }
  useEffect(() => { let active = true; setLoading(true); setError(null); request(`/md/workspace?year=${year}`).then(result => { if (active) setData(result); }).catch(err => { if (active) setError(err); }).finally(() => { if (active) setLoading(false); }); return () => { active = false; }; }, [year]);
  useEffect(() => { if (notificationTarget?.workspace === 'md') { setFilter('overdue'); setQuery(''); setDepartment('all'); setDetail(null); if (notificationTarget.year && String(notificationTarget.year) !== year) setYear(String(notificationTarget.year)); else refresh(); } }, [notificationTarget]);
  async function logout() { try { await request('/logout', { method: 'POST' }); onSession(null); } catch (err) { setError(err); } }
  const navigate = next => { onPage(next); setFilter('all'); setQuery(''); setMobile(false); };
  const scoped = (data?.activities || []).filter(row => department === 'all' || same(row.department_id, department));
  const summary = stats(scoped);
  const departments = (data?.departments || []).filter(d => department === 'all' || same(d.id, department));
  const hofs = [...new Map((data?.hofs || []).filter(h => department === 'all' || same(h.department_id, department)).map(h => [h.id, h])).values()];
  const rows = scoped.filter(row => [row.activity, row.code, row.kpa, row.kpi, row.owner_name, row.assignee].some(value => String(value || '').toLowerCase().includes(query.toLowerCase())) && (filter === 'all' || filter === 'overdue' && row.overdue || filter === 'pending' && row.pending > 0 || filter === 'unassigned' && !row.assignee || filter === 'review' && row.needs_review));
  const deptName = id => data?.departments.find(d => same(d.id, id))?.name || 'Previous department';
  const openWork = value => { onPage('work'); setQuery(value); setFilter('all'); };
  const nav = [
    ['overview', 'Executive overview', LayoutDashboard, 'The organization at a glance'],
    ['departments', 'Departments', Building2, 'Performance across departments'],
    ['hofs', 'Heads of function', Users, 'People leading each function'],
    ['work', 'Work explorer', Activity, 'Delivery, ownership & deadlines'],
    ['extra', 'Extra work', Target, 'Additional department assignments'],
  ];
  const organizationCounts = data && !loading ? {
    departments: data.departments.length,
    hofs: new Set(data.hofs.map(person => person.id)).size,
  } : {};
  const navigationItem = ([id, name, Icon, description]) => <RouteLink key={id} href={workspacePath('md', id)} aria-current={page === id ? 'page' : undefined} className={`executive-nav-link ${page === id ? 'is-current' : ''}`} onNavigate={() => navigate(id)}>
    <span className="executive-nav-icon"><Icon size={19} aria-hidden="true" /></span>
    <span className="executive-nav-copy"><strong>{name}</strong><small>{description}</small></span>
    {organizationCounts[id] !== undefined && <span className="executive-nav-count" aria-label={`${organizationCounts[id]} ${name.toLowerCase()}`}>{organizationCounts[id]}</span>}
  </RouteLink>;
  function progress(value) { return <div className="md-progress"><span>{value === null ? 'No published work' : pct(value)}</span><div><i style={{ width: `${Math.min(100, Math.max(0, value || 0))}%` }} /></div></div>; }
  return <div className="app-shell md-shell">
    {mobile && <button className="nav-scrim" aria-label="Close navigation" onClick={() => setMobile(false)} />}
    <aside className={`sidebar executive-sidebar ${mobile ? 'sidebar-open' : ''}`}>
      <RouteLink className="brand" href="/md/overview" onNavigate={() => navigate('overview')}><Brand /></RouteLink>
      <div className="executive-identity"><span className="executive-identity-icon"><ShieldCheck size={20} aria-hidden="true" /></span><div><small>EXECUTIVE OFFICE</small><strong>Managing Director</strong></div><span className="executive-identity-mark" /></div>
      <div className="executive-navigation-scroll">
        <nav aria-label="MD dashboard">
          <div className="executive-nav-group"><h2>COMMAND CENTRE</h2>{[nav[0], nav[3], nav[4]].map(navigationItem)}</div>
          <div className="executive-nav-group"><h2>ORGANIZATION</h2>{[nav[1], nav[2]].map(navigationItem)}</div>
          <div className="executive-nav-group executive-nav-utilities"><h2>INSIGHTS & DISPLAY</h2>
            <RouteLink className="executive-nav-link" href="/kpi-results"><span className="executive-nav-icon"><BarChart3 size={19} aria-hidden="true" /></span><span className="executive-nav-copy"><strong>Monthly KPI Results</strong><small>Targets, actuals & achievement</small></span><ArrowRight className="executive-link-arrow" size={14} aria-hidden="true" /></RouteLink>
            <a className="executive-nav-link" href="/display" target="_blank" rel="noopener noreferrer"><span className="executive-nav-icon"><Monitor size={19} aria-hidden="true" /></span><span className="executive-nav-copy"><strong>TV display</strong><small>Open the performance monitor</small></span><ArrowUpRight className="executive-link-arrow" size={15} aria-hidden="true" /><span className="executive-screen-reader"> (opens in a new tab)</span></a>
          </div>
        </nav>
      </div>
      <div className="executive-sidebar-footer">
        <div className="executive-access-note"><Eye size={16} aria-hidden="true" /><span>Company-wide visibility</span><small>VIEW ONLY</small></div>
        <div className="sidebar-user"><Avatar name={session.name} small /><span title={session.name}><strong>{session.name}</strong><small>Managing Director</small></span><button className="icon-button" aria-label="Sign out" title="Sign out" onClick={logout}><LogOut size={17} /></button></div>
      </div>
    </aside>
    <div className="md-main"><header className="md-topbar"><button className="icon-button md-menu" aria-label="Open navigation" onClick={() => setMobile(true)}><Menu size={20} /></button><span>CPSTL <span className="md-muted">/ Executive dashboard</span></span><Badge><ShieldCheck size={13} /> View only</Badge></header><main className="md-content">{page !== 'extra' && <div className="page-heading page-heading-compact"><div><h1>{nav.find(n => n[0] === page)?.[1]}</h1></div><button className="btn btn-secondary" disabled={loading} onClick={refresh}><RefreshCw size={16} />Refresh</button></div>}
      {page === 'extra' ? <ExtraWorkWorkspace session={session} plannedHref="/md/work" notificationTarget={notificationTarget} /> : <><div className="md-filters"><label>Performance year<select value={year} onChange={e => setYear(e.target.value)}>{[...new Set([year, ...(data?.years || []).map(String)])].sort().reverse().map(y => <option key={y}>{y}</option>)}</select></label><label>Department<select value={department} onChange={e => setDepartment(e.target.value)}><option value="all">All departments</option>{data?.departments.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}</select></label><small>{data && `Updated ${new Date(data.as_of).toLocaleString()}`}</small></div><ErrorBox error={error} />
      {loading ? <div className="empty"><LoaderCircle className="spin" /><p>Loading executive overview…</p></div> : error ? <button className="btn btn-secondary" onClick={refresh}>Retry loading</button> : data && <>
      {page === 'overview' && <><section className="md-hero"><div><span className="eyebrow">{year} · DELIVERY AT A GLANCE</span><h2>Delivery summary</h2><p>{departments.length} departments · {hofs.length} heads of function</p><span><Eye size={15} /> Approved work completion</span></div><div className="md-hero-number"><strong>{scoped.length}</strong><span>published activities</span><small>{scoped.filter(r => r.approved === 100).length} fully approved</small></div></section><section className="stats-grid">{[['Heads of function', hofs.length, 'In selected departments', Users], ['Awaiting review', summary.pending, 'Current submitted tasks', Clock], ['Overdue activities', summary.overdue, 'Past deadline, not fully approved', Activity], ['Unassigned activities', scoped.filter(r => !r.assignee).length, 'Published work without an owner', Target]].map(([label, value, hint, Icon]) => <article className="stat" key={label}><div className="stat-top"><span>{label}</span><Icon size={19} /></div><strong>{value}</strong><small>{hint}</small></article>)}</section><section className="md-attention"><div><h2>Where attention is needed</h2><p>Explore the work behind each count.</p></div>{[['overdue', summary.overdue, 'Overdue activities'], ['pending', summary.pending, 'Pending approvals'], ['review', scoped.filter(r => r.needs_review).length, 'Assignments to review']].map(([key, count, label]) => <button key={key} onClick={() => { onPage('work'); setFilter(key); setQuery(''); }}><strong>{count}</strong><span>{label}</span><ArrowRight size={16} /></button>)}</section></>}
      {page === 'overview' && <Timeliness value={summary.timeliness} />}{page === 'overview' && <WorkFocus title={"Delivery that needs attention"} subtitle={"Open an activity to see ownership, deadlines and task status"} items={scoped.filter(r => r.approved < 100).map(r => ({ id: r.key, title: r.activity, meta: r.owner_name + ' · ' + deptName(r.department_id), due: r.due_date, status: r.needs_review ? 'Assignment needs review' : r.pending ? r.pending + ' awaiting approval' : r.assignee || 'Unassigned', priority: r.needs_review ? 0 : r.pending ? 1 : undefined, onOpen: () => setDetail(r) }))} />}{page === 'overview' && <><DashboardCharts title="Organization delivery analytics" records={scoped.map(r => ({ id: r.key, label: r.activity, group: deptName(r.department_id), status: r.needs_review ? 'Review assignment' : r.approved === 100 ? 'Approved' : r.pending ? 'Awaiting review' : r.assignee ? 'Assigned' : 'Unassigned', reported: r.reported, approved: r.approved }))} /><details className="reporting-details"><summary>View organization reporting chart</summary><OrganizationChart /></details></>}{['overview', 'departments'].includes(page) && <section className="panel"><div className="panel-heading"><div><h2>Department delivery</h2><p>Weighted completion within each department’s published work; these are not organization score weights.</p></div></div><div className="md-department-grid">{departments.map(d => { const work = scoped.filter(r => same(r.department_id, d.id)), s = stats(work); return <article key={d.id}><div className="md-dept-title"><span><Building2 size={19} /></span><small>{d.code}</small></div><h3>{d.name}</h3>{progress(s.completion)}<Timeliness value={s.timeliness} compact /><p>{work.length} activities · {s.overdue} overdue · {s.pending} pending reviews</p><button className="text-button" onClick={() => { setDepartment(String(d.id)); navigate('work'); }}>Explore department <ArrowRight size={14} /></button></article>; })}{!departments.length && <p>No departments in this scope.</p>}</div></section>}
      {page === 'hofs' && <section className="panel"><div className="panel-heading"><div><h2>HOF responsibilities</h2><p>Contribution points use each HOF’s agreed plan weights. Filtered departments show only their share.</p></div></div><div className="table-scroll"><table className="md-table"><thead><tr><th>Head of function</th><th>Activities</th><th>Approved plan contribution</th><th>Pending / overdue</th><th /></tr></thead><tbody>{hofs.map(h => { const work = scoped.filter(r => same(r.owner_id, h.id)), s = stats(work); return <tr key={h.id}><td><div className="person-name"><Avatar name={h.name} /><span><strong>{h.name}</strong><small>{h.designation}</small></span></div></td><td>{work.length}</td><td>{work.length ? `${Number(s.points.toFixed(2))} points` : 'No published work'}</td><td>{s.pending} / {s.overdue}</td><td><button className="text-button" onClick={() => openWork(h.name)}>View work <ArrowRight size={14} /></button></td></tr>; })}</tbody></table></div>{!hofs.length && <Empty title="No HOFs in this scope" text="Active HOF responsibilities will appear here." />}</section>}
      {page === 'work' && <section className="panel"><div className="panel-heading"><div><h2>Work explorer <span className="count-label">{rows.length}</span></h2><p>Select an activity to see task owners, reviewers and status.</p></div></div><div className="md-work-filters"><label><Search size={16} /><input aria-label="Search executive work" placeholder="Search activities, KPIs or people…" value={query} onChange={e => setQuery(e.target.value)} /></label><select aria-label="Work status" value={filter} onChange={e => setFilter(e.target.value)}><option value="all">All work</option><option value="overdue">Overdue</option><option value="pending">Pending approval</option><option value="unassigned">Unassigned</option><option value="review">Assignment needs review</option></select></div>{!rows.length ? <Empty title="No matching published work" text="Change the filters, or wait for a Plan Editor to publish a plan." /> : <div className="table-scroll"><table className="md-table"><thead><tr><th>Activity / KPI</th><th>HOF / owner</th><th>Approved</th><th>Deadline</th><th>Status</th></tr></thead><tbody>{rows.map(r => <tr key={r.key}><td><button className="text-button" onClick={() => setDetail(r)}>{r.activity}</button><small>{r.kpi} · {deptName(r.department_id)}</small></td><td>{r.owner_name}<small>{r.assignee || 'Unassigned'}</small></td><td>{progress(r.approved)}</td><td>{r.due_date || 'Not set'}</td><td><Badge tone={r.needs_review || r.overdue ? 'draft' : r.approved === 100 ? 'mint' : 'neutral'}>{r.needs_review ? 'Review assignment' : r.overdue ? 'Overdue' : r.approved === 100 ? 'Approved' : r.pending ? 'Awaiting review' : r.assignee ? 'Assigned' : 'Unassigned'}</Badge></td></tr>)}</tbody></table></div>}</section>}
      <InfoDetails title="About completion"><div className="form-note"><CheckCircle2 size={18} /><span>Only approved work counts toward completion. Reported progress and KPI outcome achievement remain separate.</span></div></InfoDetails></>}
      </>}<footer className="workspace-footer"><span>CPSTL Performance Management</span><span>Managing Director · Executive view</span></footer></main></div>
      {detail && <Modal title={detail.activity} subtitle={`${detail.kpa} → ${detail.kpi}`} onClose={() => setDetail(null)}><div className="modal-body"><p><strong>{detail.owner_name}</strong> · {detail.plan_code} · {detail.code}</p><p>Owner: {detail.assignee || 'Unassigned'} · Deadline: {detail.due_date || 'Not set'}</p><p>Reported {pct(detail.reported)} · Approved {pct(detail.approved)}</p>{detail.needs_review && <div className="form-note">The assignment or reviewer needs review. Its approved contribution is excluded.</div>}{!detail.tasks.length ? <Empty title="No delivery tasks yet" text="The responsible person has not created a task breakdown, or a reviewer has not been designated for HOF own work." /> : detail.tasks.map(t => <article className="md-task" key={t.id}><h3>{t.title}</h3><Badge>{t.status.replaceAll('_', ' ')}</Badge><p>Owner: {t.owner}<br />Reviewer: {t.reviewer}<br />{pct(t.weight * 100)} of {t.parent_task_id ? 'parent task' : 'activity'}{t.activity_weight !== undefined && t.parent_task_id ? ` · ${pct(t.activity_weight * 100)} of activity` : ''} · Due {t.due_date || 'not set'}</p></article>)}</div><div className="modal-footer"><button className="btn btn-secondary" onClick={() => setDetail(null)}>Close</button></div></Modal>}
    </div>;
}




