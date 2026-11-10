import { registerNavigationGuard } from '../../services/navigationGuard.js';
import DashboardCharts from '../../components/DashboardCharts.jsx';
import OrganizationChart from '../organization/OrganizationChart.jsx';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ArrowLeft, ArrowRight, BarChart3, Check, CheckCircle2, ChevronRight, CircleHelp,
  ClipboardList, FileCheck2, FilePenLine, FileSpreadsheet, FolderTree, History, LayoutDashboard,
  LoaderCircle, LogOut, Menu, Plus, Save, Search, Send, ShieldCheck, Target,
  Trash2, Users, X,
} from 'lucide-react';
import { request } from '../../services/api.js';
import { Avatar, Badge, Brand, Empty, ErrorBox, Field, Modal, RouteLink } from '../../components/ui.jsx';
import './planning.css';
import PersonalAllocationWorkspace from '../allocations/PersonalAllocationWorkspace.jsx';
import MonthlyKpiWorkspace from '../kpi/MonthlyKpiWorkspace.jsx';
import { workspacePath } from '../../app/workspaceRoutes.js';

const emptyWorkspace = { plans: [], owners: [], departments: [], memberships: [], can_edit: false };
const sameId = (a, b) => String(a) === String(b);
const clone = value => structuredClone(value);
const percent = value => value === null || value === undefined || value === '' ? 'Not set' : `${Number((Number(value) * 100).toFixed(2))}%`;
const sumWeights = rows => rows.reduce((sum, row) => sum + Number(row.weight || 0), 0);
const ownerOf = (data, id) => data.owners.find(user => sameId(user.id, id));
const departmentOf = (data, id) => data.departments.find(dept => sameId(dept.id, id));
const departmentsForOwner = (data, ownerId) => data.departments.filter(dept =>
  data.memberships.some(m => sameId(m.user_id, ownerId) && sameId(m.department_id, dept.id)));
const dateLabel = value => value ? new Date(value.includes('Z') || value.includes('+') ? value : value.replace(' ', 'T') + 'Z').toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Not published';
const recent = value => value && Date.now() - new Date(value.replace(' ', 'T') + (value.includes('Z') ? '' : 'Z')).getTime() < 3 * 86400000;
const newId = prefix => `${prefix}-${crypto.randomUUID().slice(0, 8).toUpperCase()}`;

export default function PlanWorkspace({ session, onSession, page, onPage, onWork, notificationTarget }) {
  const [data, setData] = useState(emptyWorkspace);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [query, setQuery] = useState('');
  const [year, setYear] = useState('all');
  const [status, setStatus] = useState('all');
  const [modal, setModal] = useState(null);
  const [dirty, setDirty] = useState(false);
  const [pending, setPending] = useState(null);
  const [mobileNav, setMobileNav] = useState(false);
  const [toast, setToast] = useState('');
  const leaving = useRef(false);
  const allocationGuard = useRef(null);
  const editor = session.system_role === 'plan_editor';
  const roleName = editor ? 'Performance plan editor' : session.designation || 'Team member';

  async function refresh() {
    setError(null);
    try { const result = await request('/planning/workspace'); setData(result); return result; }
    catch (err) { setError(err); if (err.status === 401) onSession(null); throw err; }
    finally { setLoading(false); }
  }
  useEffect(() => { refresh().catch(() => {}); }, []);
  useEffect(() => { if (!toast) return; const timer = setTimeout(() => setToast(''), 4500); return () => clearTimeout(timer); }, [toast]);
  useEffect(() => {
    function preventLoss(e) { if (dirty && !leaving.current) { e.preventDefault(); e.returnValue = ''; } }
    window.addEventListener('beforeunload', preventLoss);
    return () => window.removeEventListener('beforeunload', preventLoss);
  }, [dirty]);

  useEffect(() => {
    if (!dirty) return;
    return registerNavigationGuard(action => setPending(() => action));
  }, [dirty]);
  function guard(action) { if (dirty) setPending(() => action); else if (allocationGuard.current) allocationGuard.current(action); else action(); }
  function leave(path) {
    leaving.current = true;
    window.location.assign(path);
  }
  function navigate(next) {
    guard(() => {
      setDetail(null); setDirty(false); setModal(null); setMobileNav(false); setQuery(''); setStatus('all'); setYear('all');
      if (next !== page) {
        if (onPage) onPage(next);
        else leave(workspacePath('planning', next));
      }
    });
  }
  async function openPlan(id, showHistory = false) {
    setDetailLoading(true); setError(null); setMobileNav(false);
    try { const result = await request(`/planning/plans/${id}`); setDetail(result); setDirty(false); if (showHistory) setModal({ kind: 'history' }); }
    catch (err) { setError(err); }
    finally { setDetailLoading(false); }
  }
  async function saved(id, message) {
    await openPlan(id);
    setToast(message);
    await refresh().catch(() => {});
  }
  async function logout() {
    try { await request('/logout', { method: 'POST' }); onSession(null); }
    catch (err) { setToast(err.message); }
  }
  const filtered = useMemo(() => data.plans.filter(plan => {
    const owner = ownerOf(data, plan.owner_id);
    return [plan.code, plan.title, owner?.name, owner?.employee_number].some(value => String(value || '').toLowerCase().includes(query.toLowerCase())) &&
      (year === 'all' || String(plan.year) === year) && (status === 'all' || plan.status === status);
  }), [data, query, year, status]);
  const published = data.plans.filter(plan => plan.status === 'published');
  const drafts = data.plans.filter(plan => plan.status === 'draft');
  const kpiCount = data.plans.reduce((sum, plan) => sum + plan.kpi_count, 0);
  const years = [...new Set(data.plans.map(plan => plan.year))].sort((a, b) => b - a);
  const canCreate = editor;
  const navigation = [
    ['overview', 'Overview', LayoutDashboard, ['overview'], 'Planning health & priorities'],
    ['plans', editor ? 'Plans & history' : 'My plans & history', FolderTree, ['plans', 'history'], 'Plans, revisions & publication'],
    ...(editor ? [['allocations', 'Personal allocations', Users, ['allocations'], 'Review personal 100% weights']] : []),
  ];
  const currentSection = navigation.find(([, , , views]) => views.includes(page));

  return <div className="app-shell planning-shell plan-editor-shell">
    {mobileNav && <button className="nav-scrim" aria-label="Close navigation" onClick={() => setMobileNav(false)} />}
    <aside className={`sidebar ${mobileNav ? 'sidebar-open' : ''}`}>
      <RouteLink className="brand" href="/planning/overview" aria-label="CPSTL performance planning overview" onNavigate={() => navigate('overview')}><Brand /></RouteLink>
      <div className="workspace-label"><span className="workspace-dot" /> PERFORMANCE WORKSPACE</div><div className="nav-caption">{editor ? 'PLAN EDITOR' : 'MY PERFORMANCE'}</div>
      <nav className="workspace-primary-nav" aria-label="Performance planning">{navigation.map(([key, label, Icon, views, caption]) => <RouteLink key={key} href={workspacePath('planning', key)} aria-current={views.includes(page) ? 'page' : undefined} className={`nav-item ${views.includes(page) ? 'nav-active' : ''}`} onNavigate={() => navigate(key)}><Icon size={20} /><span>{label}<small>{caption}</small></span>{key === 'plans' && <span className="nav-count">{data.plans.length}</span>}</RouteLink>)}</nav>
      <div className="workspace-nav-divider" /><div className="nav-caption">MEASUREMENT & REPORTS</div><nav aria-label="Planning reports"><RouteLink className={`nav-item workspace-utility-link ${page === 'results' ? 'nav-active' : ''}`} aria-current={page === 'results' ? 'page' : undefined} href="/planning/results" onNavigate={() => navigate('results')}><BarChart3 size={18} /><span>Monthly KPI Results</span></RouteLink>{onWork && <button className="nav-item workspace-utility-link" onClick={() => guard(onWork)}><ArrowLeft size={18} /><span>Work dashboard</span></button>}</nav>
      <div className="sidebar-bottom"><div className="sidebar-tip"><span className="tip-icon"><Target size={23} /></span><strong>{editor ? 'Every responsibility counts.' : 'Your agreed responsibilities.'}</strong><p>{editor ? 'One personal plan, with clear targets and a complete 100% allocation.' : 'See the latest published plan and the targets agreed for your role.'}</p><button onClick={() => setModal({ kind: 'guide' })}>Planning guide <ChevronRight size={15} /></button></div>
        <div className="sidebar-user"><Avatar name={session.name} small /><span><strong>{session.name}</strong><small>{roleName}</small></span><button className="icon-button" title="Sign out" aria-label="Sign out" onClick={() => guard(logout)}><LogOut size={17} /></button></div>
      </div>
    </aside>
    <div className="workspace"><header className="topbar"><div className="breadcrumb"><button className="icon-button mobile-menu" aria-label="Open navigation" onClick={() => setMobileNav(true)}><Menu size={22} /></button><span>CPSTL</span><ChevronRight size={14} /><strong>{detail ? detail.plan.code : page === 'results' ? 'Monthly KPI Results' : currentSection?.[1] || 'Performance planning'}</strong></div><div className="topbar-right"><span className="planning-role"><ShieldCheck size={14} />{editor ? 'Planning access' : 'Read-only access'}</span><button className="icon-button" aria-label="Open planning guide" onClick={() => setModal({ kind: 'guide' })}><CircleHelp size={20} /></button><span className="topbar-divider" /><Avatar name={session.name} small /></div></header>
      <main>
        {page === 'allocations' ? <PersonalAllocationWorkspace session={session} embedded navigationGuard={allocationGuard} onBack={() => navigate('overview')} /> : page === 'results' ? <MonthlyKpiWorkspace session={session} embedded notificationTarget={notificationTarget} /> : <>
        {['plans', 'history'].includes(page) && <nav className="workspace-section-tabs" aria-label="Plan views">{[['plans', 'Performance plans', FolderTree], ['history', 'Revision history', History]].map(([key, label, Icon]) => <RouteLink key={key} href={workspacePath('planning', key)} aria-current={page === key ? 'page' : undefined} className={page === key ? 'is-active' : ''} onNavigate={() => navigate(key)}><Icon size={17} /><span>{label}</span>{key === 'plans' && <small>{data.plans.length}</small>}</RouteLink>)}</nav>}
        {error && <div className="load-error"><ErrorBox error={error} /><button className="btn btn-secondary" onClick={() => refresh().catch(() => {})}>Retry</button></div>}
        {loading || detailLoading ? <div className="content-loading"><LoaderCircle className="spin" /><p>Opening performance plans…</p></div> : detail ? <PlanEditor key={`${detail.plan.id}-${detail.plan.revision}`} detail={detail} data={data} onDirty={setDirty} onBack={() => navigate('plans')} onSaved={saved} onHistory={() => setModal({ kind: 'history' })} /> : <>
          <div className="page-heading planning-page-heading"><div><div className="eyebrow">{editor ? 'PERFORMANCE PLANNING' : 'PUBLISHED RESPONSIBILITIES'}</div><h1>{page === 'overview' ? 'Your planning overview.' : editor ? 'Plans & history' : 'My plans & history'}</h1><p>{page === 'history' ? 'Choose a plan to review saved changes and publication history.' : editor ? 'Define KPAs, agree KPI targets and publish complete plans for your organization.' : 'Your published KPAs, KPI targets and supporting activities in one place.'}</p></div>{editor && page !== 'history' && <div className="heading-actions"><button className="btn btn-primary" onClick={() => setModal({ kind: 'create' })}><Plus size={18} /> Create plan</button></div>}</div>
          {page === 'overview' && <><DashboardCharts title="Planning analytics" groupTitle="Plans by owner" records={data.plans.map(p => ({ id: p.id, label: p.title, status: p.status === 'published' ? 'Published' : 'Draft', group: ownerOf(data, p.owner_id)?.name || 'Previous owner' }))} /><details className="reporting-details"><summary>View organization reporting chart</summary><OrganizationChart /></details>
            <section className="stats-grid" aria-label="Planning summary">{[
              [editor ? 'Personal plans' : 'Published plans', data.plans.length, editor ? 'Across the organization' : 'Available to you', FolderTree, 'mint'],
              ['Published', published.length, 'Available to plan owners', FileCheck2, 'blue'],
              [editor ? 'Draft plans' : 'Key result areas', editor ? drafts.length : data.plans.reduce((sum, p) => sum + p.kpa_count, 0), editor ? 'Being prepared or revised' : 'Defined in your plans', FilePenLine, 'sand'],
              ['KPI definitions', kpiCount, 'With agreed measurement fields', Target, 'lilac'],
            ].map(([label, value, caption, Icon, tone]) => <article className="stat" key={label}><div className="stat-top"><span>{label}</span><span className={`stat-icon ${tone}`}><Icon size={19} /></span></div><strong>{String(value).padStart(2, '0')}</strong><small><span className={`tiny-dot ${tone}`} />{caption}</small></article>)}</section>
            {editor && <div className="planning-intro"><div><span className="planning-intro-icon"><ClipboardList size={24} /></span><div><h2>A complete plan starts with a clear allocation</h2><p>A plan can cover several departments. Its KPA weights add up to one personal 100%.</p></div></div><button className="text-button" onClick={() => setModal({ kind: 'guide' })}>How planning works <ArrowRight size={16} /></button></div>}
          </>}
          {editor && !data.owners.length && <div className="form-note planning-owner-note"><Users size={20} /><span>To create a plan, add an active person to an active department.</span></div>}
          {page !== 'overview' && <section className="panel planning-list">
            <div className="panel-heading"><div><h2>{page === 'history' ? 'Plan records' : 'Performance plans'} <span className="count-label">{data.plans.length}</span></h2><p>{editor ? 'One personal plan per person for each year' : 'Published versions only'}</p></div></div>
            <div className="plan-filters"><label className="plan-search"><Search size={17} /><input aria-label="Search performance plans" placeholder="Search plans or owners…" value={query} onChange={e => setQuery(e.target.value)} /></label><select aria-label="Filter plans by year" value={year} onChange={e => setYear(e.target.value)}><option value="all">All years</option>{years.map(y => <option value={y} key={y}>{y}</option>)}</select>{editor && <select aria-label="Filter plans by status" value={status} onChange={e => setStatus(e.target.value)}><option value="all">All statuses</option><option value="draft">Draft</option><option value="published">Published</option></select>}</div>
            <PlanTable plans={filtered} data={data} editor={editor} onOpen={id => openPlan(id, page === 'history')} onCreate={() => setModal({ kind: 'create' })} canCreate={canCreate}
              onRemove={editor && page === 'plans' ? plan => setModal({ kind: 'remove', plan }) : undefined} />
          </section>}
        </>}
        </>}
        <footer className="workspace-footer"><span><span className="footer-dot" /> CPSTL Performance Management</span><span>Ceylon Petroleum Storage Terminals Limited</span></footer>
      </main>
    </div>
    {modal?.kind === 'create' && <Modal title="Create a personal plan" subtitle="Choose a department, select a person and set the performance year." onClose={() => setModal(null)}>
      <CreatePlanForm data={data} onClose={() => setModal(null)}
        onCreated={async id => { setModal(null); await saved(id, 'Draft plan created. Add its KPAs and targets.'); }} />
    </Modal>}
    {modal?.kind === 'remove' && <RemovePlanDialog plan={modal.plan} onClose={() => setModal(null)} onRemoved={() => {
      setData(current => ({ ...current, plans: current.plans.filter(plan => plan.id !== modal.plan.id) }));
      setModal(null);
      setToast('Plan deleted.');
      refresh().catch(() => {});
    }} />}
    {modal?.kind === 'history' && detail && <Modal title="Plan revision history" subtitle={`${detail.plan.code} · ${detail.plan.title}`} onClose={() => setModal(null)}><VersionHistory versions={detail.versions} data={data} /><div className="modal-footer"><button className="btn btn-primary" onClick={() => setModal(null)}>Done <Check size={16} /></button></div></Modal>}
    {modal?.kind === 'guide' && <Modal title="Performance planning guide" subtitle="Draft, review and publish a complete personal plan." onClose={() => setModal(null)}><div className="modal-body help-content">{[
      ['01', 'Choose the plan owner and year', 'Select a person with an active department assignment. Keep one plan per person per year.'],
      ['02', 'Add KPAs and KPI targets', 'Allocate 100% across KPAs. Inside each KPA, allocate 100% across its KPIs. Targets and weights are separate fields.'],
      ['03', 'Define supporting activities', 'Give each activity an agreed share of its parent KPI. Activity weights inside each KPI total 100%.'],
      ['04', 'Save and publish', 'Drafts can be incomplete. Publish after validation. Plan owners see the last published version while a new revision is being prepared.'],
    ].map(([number, title, text]) => <div className="help-step" key={number}><span>{number}</span><div><h3>{title}</h3><p>{text}</p></div></div>)}<div className="form-note"><History size={18} /><span>Each saved change keeps a revision history. Recent item changes are highlighted for three days.</span></div></div><div className="modal-footer"><button className="btn btn-primary" onClick={() => setModal(null)}>Got it <Check size={16} /></button></div></Modal>}
    {pending && <Modal title="Leave your unsaved changes?" subtitle="Your last saved draft will remain available." onClose={() => setPending(null)}><div className="modal-body"><p className="confirm-copy">You have changes that have not been saved. Stay here to save them, or discard these edits and continue.</p></div><div className="modal-footer"><button className="btn btn-secondary" onClick={() => setPending(null)}>Keep editing</button><button className="btn btn-primary" onClick={() => { const action = pending; setPending(null); setDirty(false); action(); }}>Discard edits</button></div></Modal>}
    {toast && <div className="toast" role="status"><CheckCircle2 size={18} /><span>{toast}</span><button className="icon-button" aria-label="Dismiss notification" onClick={() => setToast('')}><X size={15} /></button></div>}
  </div>;
}

function PlanTable({ plans, data, editor, onOpen, onCreate, canCreate, onRemove }) {
  if (!plans.length) return <Empty icon={FolderTree} title={editor ? 'Your next plan starts here' : 'No published plans yet'} text={editor ? 'Create a draft for a person, then add their KPAs, KPI targets and activities.' : 'Your plan editor will publish your agreed responsibilities here.'} action={canCreate ? <button className="btn btn-primary" onClick={onCreate}><Plus size={16} /> Create your first plan</button> : null} />;
  return <div className="table-scroll"><table className="plan-table"><thead><tr><th>Personal plan</th><th>Plan owner</th><th>Structure</th><th>Allocation</th><th>Status</th><th><span className="sr-only">Plan actions</span></th></tr></thead><tbody>{plans.map(plan => <tr key={plan.id}><td><button className="plan-name" onClick={() => onOpen(plan.id)}><span className="plan-document-icon"><FolderTree size={21} /></span><span><strong>{plan.title}</strong><small>{plan.code} · {plan.year}</small></span></button></td><td><div className="head-name"><Avatar name={ownerOf(data, plan.owner_id)?.name} small /><span>{ownerOf(data, plan.owner_id)?.name || 'Owner unavailable'}</span></div></td><td><span className="plan-structure">{plan.kpa_count} KPAs <span>·</span> {plan.kpi_count} KPIs<small>{plan.activity_count} activities</small></span></td><td><Allocation total={plan.weight_total} empty={!plan.kpa_count} /></td><td><PlanStatus plan={plan} /></td><td><div className="plan-row-actions">
    <button className="icon-button" aria-label={`Open ${plan.code}`} onClick={() => onOpen(plan.id)}><ArrowRight size={17} /></button>
    {onRemove && <button className="icon-button danger" title="Delete plan" aria-label={`Delete plan ${plan.code}`} onClick={() => onRemove(plan)}><Trash2 size={17} /></button>}
  </div></td></tr>)}</tbody></table></div>;
}

function RemovePlanDialog({ plan, onClose, onRemoved }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [preview, setPreview] = useState(null);
  const [loading, setLoading] = useState(true);
  const removing = useRef(false);

  async function load() {
    setLoading(true);
    setError(null);
    setPreview(null);
    try {
      setPreview(await request('/planning/plans/' + plan.id + '/deletion'));
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => { load(); }, []);

  async function remove() {
    if (removing.current || !preview) return;
    removing.current = true;
    setBusy(true);
    setError(null);
    try {
      await request('/planning/plans/' + plan.id, {
        method: 'DELETE', body: { revision: preview.revision, deletion_token: preview.deletion_token },
      });
      onRemoved();
    } catch (err) {
      setError(err);
      setPreview(null);
    } finally {
      removing.current = false;
      setBusy(false);
    }
  }

  const labels = { items: 'KPAs, KPIs and activities', revisions: 'Saved plan revisions', assignments: 'Activity assignments',
    tasks: 'Tasks and subtasks', submissions: 'Task submissions', kpi_assignments: 'KPI assignments', monthly_results: 'Monthly results' };
  return <Modal title="Delete this plan?" subtitle={preview?.plan.code || plan.code} onClose={() => { if (!removing.current) onClose(); }}>
    <div className="modal-body">
      <ErrorBox error={error} />
      {loading ? <p><LoaderCircle className="spin" size={18} /> Loading linked records…</p> : preview ? <>
        <p className="confirm-copy">This permanently deletes <strong>{preview.plan.title}</strong> and all linked work listed below.</p>
        <ul>{Object.entries(preview.counts).filter(([, count]) => count > 0).map(([key, count]) => <li key={key}>{labels[key]} · <strong>{count}</strong></li>)}</ul>
        <p className="confirm-copy">The plan’s contribution is removed from live dashboards and personal performance calculations. This action cannot be undone.</p>
      </> : <button className="btn btn-secondary" onClick={load}>Reload deletion details</button>}
    </div>
    <div className="modal-footer">
      <button className="btn btn-secondary" disabled={busy} onClick={onClose}>Cancel</button>
      <button className="btn btn-primary" disabled={busy || loading || !preview} onClick={remove}>{busy ? <LoaderCircle className="spin" size={16} /> : <Trash2 size={16} />}Delete plan</button>
    </div>
  </Modal>;
}

function PlanStatus({ plan }) {
  return <span className="plan-status-wrap"><Badge tone={plan.status === 'published' ? 'mint' : 'draft'}>{plan.status === 'published' ? 'Published' : plan.imported ? 'Excel draft' : 'Draft'}</Badge>{plan.status === 'draft' && plan.published_revision && <small>Published version available</small>}</span>;
}

function Allocation({ total, empty = false, label }) {
  const complete = Math.abs(total - 1) < 0.00005 && !empty;
  return <div className={`allocation ${complete ? 'allocation-complete' : ''}`}><div><span>{label || (empty ? 'Not allocated' : percent(total))}</span>{complete && <CheckCircle2 size={13} />}</div><span className="allocation-track"><span style={{ width: `${Math.min(Math.max(total, 0), 1) * 100}%` }} /></span></div>;
}

function CreatePlanForm({ data, onClose, onCreated }) {
  const currentYear = new Date().getFullYear();
  const [departmentId, setDepartmentId] = useState('');
  const [form, setForm] = useState({ title: '', code: null, owner_id: '', year: currentYear, kpas: [] });
  const [error, setError] = useState(null), [busy, setBusy] = useState(false);
  async function submit(e) {
    e.preventDefault(); setBusy(true); setError(null);
    try { const result = await request('/planning/plans', { method: 'POST', body: { ...form, code, owner_id: Number(form.owner_id), year: Number(form.year) } }); await onCreated(result.id); }
    catch (err) { setError(err); }
    finally { setBusy(false); }
  }
  const selectedOwner = ownerOf(data, form.owner_id);
  const owners = data.owners.filter(owner => data.memberships.some(membership =>
    sameId(membership.user_id, owner.id) && sameId(membership.department_id, departmentId)));
  const departments = departmentsForOwner(data, form.owner_id);
  const code = form.code ?? (selectedOwner && form.year ? `PLAN-${form.year}-${selectedOwner.employee_number || selectedOwner.id}`.toUpperCase().replace(/[^A-Z0-9_-]/g, '').slice(0, 64) : '');
  return <form onSubmit={submit}>
    <div className="modal-body">
      <ErrorBox error={error} />
      <Field label="Department" required>
        <select value={departmentId} required disabled={busy} onChange={event => {
          setDepartmentId(event.target.value);
          setForm(previous => ({ ...previous, owner_id: '' }));
          setError(null);
        }}>
          <option value="">Select department</option>
          {data.departments.map(department => <option key={department.id} value={department.id}>{department.name}</option>)}
        </select>
      </Field>
      <Field label="Plan title" required>
        <input value={form.title} onChange={event => setForm({ ...form, title: event.target.value })}
          maxLength={180} placeholder="e.g. 2026 IT and digital services plan" required />
      </Field>
      <div className="form-grid">
        <Field label="Plan owner" required hint={departmentId ? `${owners.length} active people in this department.` : 'Choose a department to see its people.'}>
          <select value={form.owner_id} disabled={busy || !departmentId || !owners.length}
            onChange={event => setForm({ ...form, owner_id: event.target.value })} required>
            <option value="">{departmentId ? owners.length ? 'Select a person' : 'No active people in this department' : 'Select department first'}</option>
            {owners.map(owner => <option key={owner.id} value={owner.id}>{owner.name} · {owner.employee_number}{owner.designation ? ` · ${owner.designation}` : ''}</option>)}
          </select>
        </Field>
        <Field label="Performance year" required>
          <input type="number" min="2000" max="2100" value={form.year}
            onChange={event => setForm({ ...form, year: event.target.value })} required />
        </Field>
      </div>
      <Field label="Plan ID" required hint="A permanent identifier. Letters, numbers, hyphens and underscores.">
        <input value={code} onChange={event => setForm({ ...form, code: event.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '') })}
          maxLength={64} placeholder="PLAN-2026-EMP001" required />
      </Field>
      {selectedOwner && <div className="selected-owner">
        <Avatar name={selectedOwner.name} />
        <div><strong>{selectedOwner.name}</strong><span>{selectedOwner.designation}</span>
          <div className="owner-departments">{departments.map(department => <Badge key={department.id}>{department.name}</Badge>)}</div>
        </div>
      </div>}
      <div className="form-note"><Target size={18} /><span>The selected person has one plan per year, with KPA weights adding up to 100%. You can save incomplete allocations as a draft.</span></div>
    </div>
    <div className="modal-footer">
      <button className="btn btn-secondary" type="button" onClick={onClose} disabled={busy}>Cancel</button>
      <button className="btn btn-primary" disabled={busy || !departmentId || !owners.some(owner => sameId(owner.id, form.owner_id))}>
        {busy ? <LoaderCircle size={17} className="spin" /> : <Plus size={17} />}Create draft
      </button>
    </div>
  </form>;
}

function PlanEditor({ detail, data, onDirty, onBack, onSaved, onHistory }) {
  const [draft, setDraft] = useState(() => clone(detail.plan));
  const [busy, setBusy] = useState(false), [error, setError] = useState(null);
  const [confirmPublish, setConfirmPublish] = useState(false), [remove, setRemove] = useState(null);
  const dirty = JSON.stringify(draft) !== JSON.stringify(detail.plan);
  const editor = detail.can_edit;
  const owner = ownerOf(data, draft.owner_id);
  const availableDepartments = departmentsForOwner(data, draft.owner_id);
  const issues = detail.issues || [];
  const total = sumWeights(draft.kpas);
  const kpiCount = draft.kpas.reduce((sum, kpa) => sum + kpa.kpis.length, 0);
  const activityCount = draft.kpas.reduce((sum, kpa) => sum + kpa.kpis.reduce((sub, kpi) => sub + kpi.activities.length, 0), 0);
  useEffect(() => { onDirty(dirty); }, [dirty, onDirty]);
  function change(action) { setDraft(previous => { const next = clone(previous); action(next); return next; }); }
  function addKpa() { change(next => next.kpas.push({ code: newId('KPA'), title: '', department_id: availableDepartments.length === 1 ? availableDepartments[0].id : '', category: '', weight: null, notes: '', kpis: [] })); }
  async function save(e) {
    e.preventDefault(); setBusy(true); setError(null);
    try { await request(`/planning/plans/${draft.id}`, { method: 'PUT', body: draft }); await onSaved(draft.id, 'Draft saved. Your revision history is up to date.'); }
    catch (err) { setError(err); }
    finally { setBusy(false); }
  }
  async function publish() {
    setBusy(true); setError(null);
    try { await request(`/planning/plans/${draft.id}/publish`, { method: 'POST', body: { revision: draft.revision } }); setConfirmPublish(false); await onSaved(draft.id, 'Plan published. The plan owner can now view this version.'); }
    catch (err) { setConfirmPublish(false); setError(err); }
    finally { setBusy(false); }
  }
  return <>
    <button className="back-button" onClick={onBack}><ArrowLeft size={16} />All performance plans</button>
    <div className="page-heading editor-heading"><div><div className="eyebrow">{draft.code} <span className="editor-revision">VERSION {draft.revision}</span></div><h1>{draft.title}</h1><p>{owner?.name || 'Owner unavailable'} <span className="separator-dot">·</span> {draft.year} <span className="separator-dot">·</span> {editor ? 'Personal performance plan' : 'Published personal plan'}</p></div><div className="editor-heading-status"><PlanStatus plan={{ ...draft, imported: !!draft.excel_source, published_revision: detail.published_revision }} /><button className="text-button" onClick={onHistory}><History size={15} />Revision history</button></div></div>
    {draft.excel_source && <p className="form-note"><FileSpreadsheet size={17} /><span>Excel source · {draft.excel_source.filename} · {draft.excel_source.sheet}</span></p>}
    <ErrorBox error={error} />
    <section className="plan-summary-bar"><div><span>KPAs</span><strong>{draft.kpas.length}</strong></div><div><span>KPIs</span><strong>{kpiCount}</strong></div><div><span>Activities</span><strong>{activityCount}</strong></div><div className="summary-allocation"><span>Personal plan allocation</span><Allocation total={total} empty={!draft.kpas.length} /></div><div><span>{editor ? 'Last published' : 'Published on'}</span><strong className="summary-date">{dateLabel(draft.published_at)}</strong></div></section>
    {!editor ? <PublishedPlan plan={draft} data={data} /> : <>
      {detail.published_revision && draft.status === 'draft' && <div className="published-note"><FileCheck2 size={18} /><p>A published version is available to the plan owner. These draft edits stay private until you publish a new version.</p></div>}
      <form id="performance-plan-form" onSubmit={save}>
        <fieldset className="plan-fieldset" disabled={busy}><section className="panel plan-title-panel"><Field label="Plan title" required><input value={draft.title} onChange={e => change(next => { next.title = e.target.value; })} maxLength={180} required /></Field><div className="plan-fixed-details"><span><Users size={15} />{owner?.name}</span><span><FolderTree size={15} />{draft.code}</span><span>{draft.year}</span></div></section>
          <div className="plan-section-heading"><div><h2>Key performance areas</h2><p>Allocate one 100% total across every department in this personal plan.</p></div><button className="btn btn-secondary" type="button" onClick={addKpa} disabled={draft.kpas.length >= 20}><Plus size={16} />Add KPA</button></div>
          {!draft.kpas.length && <section className="panel"><Empty icon={Target} title="Add the first area of responsibility" text="Start with a KPA, then add the KPIs and activities that support it." action={<button className="btn btn-primary" type="button" onClick={addKpa}><Plus size={16} />Add first KPA</button>} /></section>}
          {draft.kpas.map((kpa, i) => <details className="kpa-editor" key={kpa.code} open><summary><span className="kpa-number">{String(i + 1).padStart(2, '0')}</span><span><small>{kpa.code}</small><strong>{kpa.title || 'New key performance area'}</strong></span><div className="item-summary-right">{recent(kpa.changed_at) && <span className="recent-label">Recent change</span>}<span className="weight-pill">{percent(kpa.weight)}</span><ChevronRight size={17} /></div></summary><div className="kpa-editor-body"><div className="item-actions"><span>KPA details</span><button className="text-button remove-item" type="button" onClick={() => setRemove({ label: kpa.title || kpa.code, action: () => change(next => next.kpas.splice(i, 1)) })}><Trash2 size={14} />Remove KPA</button></div>
            <Field label="KPA title" required><textarea rows={2} value={kpa.title} onChange={e => change(next => { next.kpas[i].title = e.target.value; })} maxLength={1000} placeholder="Describe the area of responsibility" required /></Field><div className="kpa-fields"><Field label="Department" required><select value={kpa.department_id} required onChange={e => change(next => { next.kpas[i].department_id = Number(e.target.value); })}><option value="">Select department</option>{availableDepartments.map(dept => <option key={dept.id} value={dept.id}>{dept.name}</option>)}</select></Field><Field label="Balanced scorecard category"><input value={kpa.category || ''} maxLength={100} placeholder="e.g. Finance" onChange={e => change(next => { next.kpas[i].category = e.target.value; })} /></Field><WeightField label="Weight in personal plan" value={kpa.weight} onChange={value => change(next => { next.kpas[i].weight = value; })} /></div><Field label="KPA notes"><input value={kpa.notes || ''} maxLength={1000} onChange={e => change(next => { next.kpas[i].notes = e.target.value; })} placeholder="Optional context" /></Field>
            <div className="nested-section-heading"><div><h3>KPIs in this KPA</h3><span>{percent(sumWeights(kpa.kpis))} allocated</span></div><button type="button" className="btn btn-secondary btn-small" disabled={kpa.kpis.length >= 30} onClick={() => change(next => next.kpas[i].kpis.push({ code: newId('KPI'), title: '', weight: null, target_value: null, target_unit: '', frequency: '', direction: '', notes: '', activities: [] }))}><Plus size={14} />Add KPI</button></div>
            {!kpa.kpis.length && <div className="nested-empty">No KPIs yet. Add the measures used to assess this responsibility.</div>}
            {kpa.kpis.map((kpi, j) => <section className="kpi-editor" key={kpi.code}><div className="kpi-editor-heading"><div><Target size={17} /><strong>{kpi.code}</strong>{recent(kpi.changed_at) && <span className="recent-label">Recent change</span>}</div><button type="button" className="icon-button danger" aria-label={`Remove ${kpi.code}`} onClick={() => setRemove({ label: kpi.title || kpi.code, action: () => change(next => next.kpas[i].kpis.splice(j, 1)) })}><Trash2 size={15} /></button></div><Field label="KPI title" required><input value={kpi.title} maxLength={1000} placeholder="e.g. Capital Budget Utilization Rate" required onChange={e => change(next => { next.kpas[i].kpis[j].title = e.target.value; })} /></Field><div className="kpi-fields"><WeightField label="Weight within KPA" value={kpi.weight} onChange={value => change(next => { next.kpas[i].kpis[j].weight = value; })} /><Field label="Target value" hint="Enter 30 for a 30% target."><input type="number" step="0.0001" min="-9999999999999" max="9999999999999" value={kpi.target_value ?? ''} placeholder="e.g. 30" onChange={e => change(next => { next.kpas[i].kpis[j].target_value = e.target.value === '' ? null : Number(e.target.value); })} /></Field><Field label="Target unit"><select value={kpi.target_unit || ''} onChange={e => change(next => { next.kpas[i].kpis[j].target_unit = e.target.value; })}><option value="">Select unit</option>{['percent', 'count', 'LKR', 'days', 'hours', 'other'].map(unit => <option key={unit} value={unit}>{unit === 'percent' ? 'Percent (%)' : unit}</option>)}</select></Field><Field label="Monitoring frequency"><select value={kpi.frequency || ''} onChange={e => change(next => { next.kpas[i].kpis[j].frequency = e.target.value; })}><option value="">Select frequency</option>{['Monthly', 'Quarterly', 'Annually', 'On completion'].map(frequency => <option key={frequency}>{frequency}</option>)}</select></Field><Field label="Target direction"><select value={kpi.direction || ''} onChange={e => change(next => { next.kpas[i].kpis[j].direction = e.target.value; })}><option value="">Select direction</option><option value="higher">Higher is better</option><option value="lower">Lower is better</option></select></Field><Field label="KPI notes"><textarea rows={draft.excel_source ? 4 : 2} value={kpi.notes || ''} maxLength={1000} placeholder="Optional measurement details" onChange={e => change(next => { next.kpas[i].kpis[j].notes = e.target.value; })} /></Field></div>
              <div className="activities-heading"><div><h4>Supporting activities</h4><small>{percent(sumWeights(kpi.activities))} allocated within this KPI</small></div><button type="button" className="text-button" disabled={kpi.activities.length >= 50} onClick={() => change(next => next.kpas[i].kpis[j].activities.push({ code: newId('ACT'), title: '', weight: null, due_date: '', notes: '' }))}><Plus size={14} />Add activity</button></div>
              {!kpi.activities.length && <div className="nested-empty activity-empty">Add the work needed to deliver this KPI.</div>}
              {kpi.activities.map((activity, k) => <div className="activity-editor" key={activity.code}><div className="activity-editor-label"><span>{activity.code}</span>{recent(activity.changed_at) && <span className="recent-label">Recent change</span>}<button type="button" className="icon-button danger" aria-label={`Remove ${activity.code}`} onClick={() => setRemove({ label: activity.title || activity.code, action: () => change(next => next.kpas[i].kpis[j].activities.splice(k, 1)) })}><Trash2 size={14} /></button></div><Field label="Activity description" required><textarea rows={2} value={activity.title} maxLength={1000} placeholder="Describe the work to be done" required onChange={e => change(next => { next.kpas[i].kpis[j].activities[k].title = e.target.value; })} /></Field><div className="activity-fields"><WeightField label="Weight within KPI" value={activity.weight} onChange={value => change(next => { next.kpas[i].kpis[j].activities[k].weight = value; })} /><Field label="Due date (optional)"><input type="date" value={activity.due_date || ''} onChange={e => change(next => { next.kpas[i].kpis[j].activities[k].due_date = e.target.value; })} /></Field><Field label="Activity notes"><input value={activity.notes || ''} maxLength={1000} placeholder="Optional" onChange={e => change(next => { next.kpas[i].kpis[j].activities[k].notes = e.target.value; })} /></Field></div></div>)}
            </section>)}
          </div></details>)}
        </fieldset>
      </form>
      <section className={`publication-check panel ${!issues.length && !dirty ? 'publication-ready' : ''}`}><div><ShieldCheck size={20} /><div><h3>{dirty ? 'Save to check publication readiness' : issues.length ? `${issues.length} item${issues.length === 1 ? '' : 's'} to resolve before publishing` : 'Ready to publish'}</h3><p>{dirty ? 'Your current edits have not been saved or validated for publication.' : issues.length ? 'Drafts can stay incomplete. Resolve these details before publishing.' : 'The saved allocation and required KPI fields are complete.'}</p></div></div>{!dirty && issues.length > 0 && <ul>{issues.map((issue, i) => <li key={i}>{issue}</li>)}</ul>}</section>
      <div className="plan-save-bar"><div><span className={dirty ? 'unsaved-dot' : 'saved-dot'} />{dirty ? 'Unsaved changes' : `Saved version ${draft.revision}`}<small>{draft.status === 'published' ? 'Published' : 'Draft'}</small></div><div><button type="submit" form="performance-plan-form" className="btn btn-secondary" disabled={busy || !dirty}>{busy ? <LoaderCircle className="spin" size={16} /> : <Save size={16} />}Save draft</button><button className="btn btn-primary" disabled={busy || dirty || issues.length > 0 || draft.status === 'published'} onClick={() => setConfirmPublish(true)}><Send size={16} />Publish plan</button></div></div>
    </>}
    {confirmPublish && <Modal title="Publish this performance plan?" subtitle={`${draft.code} · version ${draft.revision}`} onClose={() => { if (!busy) setConfirmPublish(false); }}><div className="modal-body"><p className="confirm-copy">This publishes the saved plan for <strong>{owner?.name}</strong>. The plan owner will see this version. Future edits will start a new draft while this published version stays available.</p></div><div className="modal-footer"><button className="btn btn-secondary" disabled={busy} onClick={() => setConfirmPublish(false)}>Cancel</button><button className="btn btn-primary" disabled={busy} onClick={publish}>{busy ? <LoaderCircle className="spin" size={16} /> : <Send size={16} />}Publish now</button></div></Modal>}
    {remove && <Modal title="Remove this responsibility?" subtitle="The removal is recorded when you save the draft." onClose={() => setRemove(null)}><div className="modal-body"><p className="confirm-copy"><strong>{remove.label}</strong> and its child items will be removed from the current draft. Previously saved revisions remain in the history.</p></div><div className="modal-footer"><button className="btn btn-secondary" onClick={() => setRemove(null)}>Keep item</button><button className="btn btn-primary" onClick={() => { remove.action(); setRemove(null); }}>Remove from draft</button></div></Modal>}
  </>;
}

function WeightField({ label, value, onChange }) {
  return <Field label={label}><div className="percentage-input"><input type="number" min="0" max="100" step="0.01" value={value === null || value === undefined ? '' : Number((value * 100).toFixed(2))} placeholder="0" onChange={e => onChange(e.target.value === '' ? null : Math.round(Number(e.target.value) * 100) / 10000)} /><span>%</span></div></Field>;
}

function PublishedPlan({ plan, data }) {
  return <div className="published-plan">{plan.kpas.map((kpa, i) => <section className="panel published-kpa" key={kpa.code}><header><span className="kpa-number">{String(i + 1).padStart(2, '0')}</span><div><small>{kpa.code} · {departmentOf(data, kpa.department_id)?.name || 'Department'}{kpa.category ? ` · ${kpa.category}` : ''}</small><h2>{kpa.title}</h2></div><span className="weight-pill">{percent(kpa.weight)}</span></header>{kpa.notes && <p className="published-notes">{kpa.notes}</p>}{kpa.kpis.map(kpi => <div className="published-kpi" key={kpi.code}><div className="published-kpi-heading"><div><small>{kpi.code}</small><h3>{kpi.title}</h3></div><Badge>{percent(kpi.weight)} of KPA</Badge></div><div className="published-targets"><span><Target size={16} /><strong>{kpi.target_value}{kpi.target_unit === 'percent' ? '%' : ` ${kpi.target_unit}`}</strong><small>Target</small></span><span>{kpi.frequency}</span><span>{kpi.direction === 'lower' ? 'Lower is better' : 'Higher is better'}</span></div>{kpi.notes && <p className="published-notes">{kpi.notes}</p>}<ul className="published-activities">{kpi.activities.map(activity => <li key={activity.code}><CheckCircle2 size={16} /><div><strong>{activity.title}</strong><small>{activity.code}{activity.due_date ? ` · Due ${activity.due_date}` : ''}</small>{activity.notes && <p>{activity.notes}</p>}</div><span>{percent(activity.weight)}</span></li>)}</ul></div>)}</section>)}</div>;
}

function VersionHistory({ versions, data }) {
  const labels = { title: 'Title', weight: 'Weight', target_value: 'Target', target_unit: 'Unit', frequency: 'Frequency', direction: 'Direction', due_date: 'Due date', notes: 'Notes', category: 'Scorecard category', department_id: 'Department', display_order: 'Order', is_active: 'Included in plan', parent_id: 'Parent responsibility' };
  const display = (field, value) => value === null || value === undefined || value === '' ? 'Not set' : field === 'weight' ? percent(value) : field === 'department_id' ? departmentOf(data, value)?.name || 'Department changed' : field === 'parent_id' ? 'Placement updated' : field === 'is_active' ? (Number(value) ? 'Yes' : 'No') : String(value);
  return <div className="modal-body version-history">{!versions.length ? <Empty icon={History} title="No revisions yet" text="Saved changes will appear here." /> : versions.map(version => <section className="version-entry" key={version.id}><div className="version-entry-heading"><span className={`version-icon ${version.event === 'published' ? 'version-published' : ''}`}>{version.event === 'published' ? <Send size={17} /> : <Save size={17} />}</span><div><h3>Version {version.version} <Badge tone={version.event === 'published' ? 'mint' : 'neutral'}>{version.event === 'published' ? 'Published' : version.event === 'created' ? 'Draft created' : 'Draft updated'}</Badge></h3><p>{version.actor_name} · {dateLabel(version.created_at)}</p></div></div>{version.changes.length > 0 ? <div className="version-changes">{version.changes.map((change, i) => <details key={`${change.code}-${i}`}><summary><Badge tone={change.type === 'added' ? 'mint' : change.type === 'removed' ? 'draft' : 'neutral'}>{change.type}</Badge><span><small>{change.code}</small>{change.title}</span>{Object.keys(change.fields).length > 0 && <ChevronRight size={14} />}</summary>{Object.entries(change.fields).length > 0 && <dl>{Object.entries(change.fields).map(([field, values]) => <div key={field}><dt>{labels[field] || field}</dt><dd><span>{display(field, values.before)}</span><ArrowRight size={12} /><strong>{display(field, values.after)}</strong></dd></div>)}</dl>}</details>)}</div> : <p className="version-publication-note">{version.event === 'published' ? 'The saved plan became available to its owner.' : 'The personal plan was created.'}</p>}</section>)}</div>;
}


