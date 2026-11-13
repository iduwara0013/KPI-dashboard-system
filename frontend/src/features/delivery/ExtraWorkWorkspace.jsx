import WorkChangePanel from './WorkChangePanel.jsx';
import { useEffect, useRef, useState } from 'react';
import { Clock3, History, Plus, RefreshCw, Save, Target } from 'lucide-react';
import { request } from '../../services/api.js';
import { Badge, Empty, ErrorBox, Field, Modal, RouteLink } from '../../components/ui.jsx';
import Timeliness from '../../components/Timeliness.jsx';
import TaskCard from './TaskCard.jsx';
import TaskAction from './TaskAction.jsx';
import BreakdownForm from './BreakdownForm.jsx';
import SubmissionHistory from './SubmissionHistory.jsx';
import { weightedTimeliness } from './timeliness.js';
import './extra-work.css';

const same = (a, b) => String(a) === String(b);
const empty = { assignments: [], tasks: [], reviews: [], review_history: [], submissions: [], personal_sources: [], people: [], departments: [], employees: [], recipients: [] };
const percent = value => value == null ? '—' : `${Number(Number(value).toFixed(1))}%`;

export default function ExtraWorkWorkspace({ session, plannedHref, notificationTarget, title = 'Extra work' }) {
  const [data, setData] = useState(empty), [loading, setLoading] = useState(true), [error, setError] = useState(null);
  const [view, setView] = useState(window.location.hash === '#work-changes' ? 'changes' : session.system_role === 'member' ? 'mine' : 'department');
  const [year, setYear] = useState(String(new Date().getFullYear())), [department, setDepartment] = useState('all'), [query, setQuery] = useState('');
  const [modal, setModal] = useState(null), [message, setMessage] = useState('');
  const opened = useRef(null);
  async function refresh() {
    try { setData({ ...await request('/extra-work/workspace'), notificationToken: notificationTarget?.token, refreshKey: Date.now() }); setError(null); }
    catch (err) { setError(err); } finally { setLoading(false); }
  }
  useEffect(() => { refresh(); }, [notificationTarget?.token]);
  useEffect(() => {
    if (notificationTarget?.workspace !== 'extra' || data.notificationToken !== notificationTarget.token || opened.current === notificationTarget.token) return;
    opened.current = notificationTarget.token;
    if (notificationTarget.work_change_id) { setYear('all'); setDepartment('all'); setQuery(''); setView('changes'); return; }
    const task = [...data.tasks, ...data.review_history].find(task => same(task.id, notificationTarget.extra_task_id));
    if (!task) { setError(new Error('This extra work is no longer available in your reporting scope.')); return; }
    setYear(String(task.parent.year)); setDepartment('all'); setQuery('');
    setView(notificationTarget.page === 'reviews' ? 'reviews' : 'mine');
    const latest = data.submissions.filter(row => same(row.task_id, task.id)).sort((a, b) => b.id - a.id)[0];
    const canReview = notificationTarget.page === 'reviews' && task.status === 'submitted' && same(task.reviewer_id, session.id)
      && (!notificationTarget.extra_submission_id || same(latest?.id, notificationTarget.extra_submission_id));
    setModal({ kind: canReview ? 'review' : 'history', task });
  }, [data, notificationTarget, session.id]);
  async function saved(text) { setModal(null); setMessage(text); await refresh(); }
  const person = id => data.people.find(person => same(person.id, id))?.name || 'Previous team member';
  const departmentName = id => data.departments.find(item => same(item.id, id))?.name || 'Department';
  const inScope = row => (year === 'all' || same(row.year, year)) && (department === 'all' || same(row.department_id, department));
  const matches = row => inScope(row.parent || row) && `${row.title || ''} ${row.parent?.activity || ''} ${person(row.assignee_id)}`.toLowerCase().includes(query.toLowerCase());
  const rows = data.assignments.filter(matches).filter(row => view !== 'assigned' || same(row.owner_id, session.id));
  const tasks = (view === 'reviews' ? data.reviews : data.tasks).filter(matches);
  const ownTaskIds = new Set(data.tasks.filter(matches).map(task => String(task.id)));
  const sources = view === 'mine' ? data.personal_sources.filter(row => inScope(row) && ownTaskIds.has(String(row.task_id))) : rows.filter(row => !row.used_for_replacement);
  const total = sources.reduce((sum, row) => sum + Number(row.weight), 0);
  const completion = total ? sources.reduce((sum, row) => sum + Number(row.weight) * Number(row.confirmed_progress), 0) / total : null;
  const years = [...new Set([String(new Date().getFullYear()), ...data.assignments.map(row => String(row.year))])].sort().reverse();
  const submissionsFor = task => data.submissions.filter(row => same(row.task_id, task.id)).sort((a, b) => b.id - a.id);
  const tabs = data.read_only ? [['department', 'Department work'], ['changes', 'Work changes']] : [['mine', 'My extra tasks'], ['assigned', 'Assigned by me'], ['reviews', `Reviews (${data.reviews.length})`], ['changes', 'Work changes'], ...(session.workspaces?.includes('hof') ? [['department', 'Department work']] : [])];
  async function history(row) {
    try { const result = await request(`/extra-work/assignments/${row.id}/history`); setModal({ kind: 'versions', row, versions: result.versions }); }
    catch (err) { setError(err); }
  }
  function action(kind, task) { setModal(kind === 'breakdown' ? { kind, parent: { ...task.parent, id: task.id, version: task.version, activity: task.title, tasks: task.children, task_branch: true, breakdown_locked: task.breakdown_locked } } : { kind, task }); }
  return <div className="extra-work-workspace">
    <div className="page-heading page-heading-compact"><h1>{title}</h1><div className="heading-actions"><button className="btn btn-secondary" disabled={loading} onClick={refresh}><RefreshCw size={16} />Refresh</button>{data.can_assign && <button className="btn btn-primary" onClick={() => setModal({ kind: 'assign' })}><Plus size={17} />Assign extra work</button>}</div></div>
    <nav className="workspace-section-tabs" aria-label="Work category"><RouteLink href={plannedHref}>Planned work</RouteLink><span className="is-active" aria-current="page">Extra work</span></nav>
    <p className="extra-work-note">Extra work stays separate unless an approved work-change request replaces planned work.</p>
    <ErrorBox error={error} />{message && <p role="status" className="extra-work-notice">{message}</p>}
    {loading ? <p>Loading extra work...</p> : <>
      <div className="extra-work-filters"><Field label="Year"><select value={year} onChange={e => setYear(e.target.value)}><option value="all">All years</option>{years.map(value => <option key={value}>{value}</option>)}</select></Field><Field label="Department">{data.departments.length > 1 ? <select value={department} onChange={e => setDepartment(e.target.value)}><option value="all">All permitted departments</option>{data.departments.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select> : <span className="extra-work-department-name">{data.departments[0]?.name || 'No department assigned'}</span>}</Field><Field label="Search extra work"><input value={query} onChange={e => setQuery(e.target.value)} placeholder="Find work or people..." /></Field></div>
      <nav className="extra-work-tabs" aria-label="Extra-work views">{tabs.map(([key, title]) => <button key={key} className={view === key ? 'is-active' : ''} aria-pressed={view === key} onClick={() => setView(key)}>{title}</button>)}</nav>
      {!['reviews', 'changes'].includes(view) && <section className="extra-work-summary" aria-label="Extra-work summary"><article><span><Target size={16} />Extra-work completion</span><strong>{percent(completion)}</strong><small>{sources.filter(row => Number(row.confirmed_progress) === 100).length} of {sources.length} responsibilities completed</small></article><article><span><Clock3 size={16} />Timeliness</span><Timeliness value={weightedTimeliness(sources)} compact /></article></section>}
      {view === 'changes' ? <WorkChangePanel notificationTarget={notificationTarget} onChanged={refresh} refreshKey={data.refreshKey} year={year} department={department} query={query} /> : ['mine', 'reviews'].includes(view) ? <section className="panel"><div className="panel-heading"><h2>{view === 'reviews' ? 'Extra work awaiting review' : 'My extra tasks'} <span className="count-label">{tasks.length}</span></h2></div>{!tasks.length ? <Empty title={view === 'reviews' ? 'No extra work awaiting review' : 'No extra tasks in this scope'} text="Assigned extra work appears here separately from your planned tasks." /> : tasks.map(task => <TaskCard key={task.id} task={task} reviewing={view === 'reviews'} readOnly={!task.assignee_eligible} person={person} department={departmentName} submissions={submissionsFor(task)} onAction={kind => action(kind, task)} />)}</section> : <section className="panel"><div className="panel-heading"><h2>{view === 'assigned' ? 'Extra work assigned by me' : 'Department extra work'} <span className="count-label">{rows.length}</span></h2></div>{!rows.length ? <Empty title="No extra work in this scope" text="Choose another year or department, or assign extra work." /> : rows.map(row => <article className="extra-work-row" key={row.id}><div><div className="extra-work-badges"><Badge>{row.priority}</Badge>{row.used_for_replacement && <Badge tone="mint">Counted through approved replacement</Badge>}<small>{departmentName(row.department_id)} · {row.year}</small>{!row.valid && <Badge tone="draft">Reporting needs attention</Badge>}</div><h3>{row.title}</h3><p>Assigned to {person(row.assignee_id)} · Reviewer {person(row.owner_id)}</p><small>Due {row.due_date?.slice(0, 10)} · Weight {Number(row.weight)}</small><Timeliness value={row.timeliness} compact /></div><div className="extra-work-row-actions"><strong>{percent(row.confirmed_progress)}<small>confirmed completion</small></strong>{row.can_change_weight && <button className="btn btn-secondary btn-small" onClick={() => setModal({ kind: 'weight', row })}>Approve weight change</button>}{row.can_view_history && <button className="text-button" onClick={() => history(row)}><History size={15} />Weight & score history</button>}</div></article>)}</section>}
    </>}
    {modal && <Modal className="delivery-modal" title={modal.kind === 'assign' ? 'Assign extra work' : modal.kind === 'weight' ? 'Approve extra-work weight change' : modal.kind === 'versions' ? 'Extra-work weight & score history' : modal.kind === 'history' ? 'Extra-work evidence & review history' : modal.kind === 'breakdown' ? 'Divide extra work' : modal.kind === 'deadline' ? 'Approve deadline extension' : modal.kind === 'review' ? 'Review extra work' : modal.kind === 'progress' ? 'Update extra-work progress' : 'Submit extra work'} subtitle={modal.task?.title || modal.row?.title || modal.parent?.activity} onClose={() => setModal(null)}>
      {modal.kind === 'assign' || modal.kind === 'weight' ? <ExtraWorkForm data={data} row={modal.row} onClose={() => setModal(null)} onSaved={async text => { if (!modal.row) setView('assigned'); await saved(text); }} /> : modal.kind === 'versions' ? <><div className="modal-body">{modal.versions.map(version => { const snapshot = JSON.parse(version.snapshot); return <article className="extra-work-version" key={version.id}><strong>{version.event === 'assigned' ? 'Work assigned' : 'Weight change approved'} · v{version.version}</strong><p>{version.actor_name} · {version.reason || 'Initial assignment'}</p><p>Weight: {Number(snapshot.assignment.weight)} · {String(version.created_at).slice(0, 16)} UTC</p><p>Department extra-work completion: {percent(snapshot.before.confirmed_completion)} → {percent(snapshot.after.confirmed_completion)}</p></article>; })}</div><div className="modal-footer"><button className="btn btn-secondary" onClick={() => setModal(null)}>Close</button></div></> : modal.kind === 'history' ? <><SubmissionHistory rows={submissionsFor(modal.task)} person={person} /><div className="modal-footer"><button className="btn btn-secondary" onClick={() => setModal(null)}>Close</button></div></> : modal.kind === 'breakdown' ? <BreakdownForm parent={modal.parent} data={data} session={session} apiBase="/extra-work" onClose={() => setModal(null)} onSaved={saved} /> : <TaskAction kind={modal.kind} task={modal.task} submissions={submissionsFor(modal.task)} person={person} apiBase="/extra-work" onClose={() => setModal(null)} onSaved={saved} />}
    </Modal>}
  </div>;
}

function ExtraWorkForm({ data, row, onClose, onSaved }) {
  const [form, setForm] = useState(row ? { weight: Number(row.weight), reason: '' } : { title: '', instructions: '', department_id: '', assignee_id: '', year: new Date().getFullYear(), due_date: '', priority: 'normal', weight: 100 });
  const [error, setError] = useState(null), [busy, setBusy] = useState(false);
  const set = (field, value) => setForm(previous => ({ ...previous, [field]: value }));
  async function submit(event) {
    event.preventDefault(); setBusy(true); setError(null);
    try { const result = await request(row ? `/extra-work/assignments/${row.id}/weight` : '/extra-work/assignments', { method: row ? 'PUT' : 'POST', body: row ? { version: Number(row.version), weight: Number(form.weight), reason: form.reason } : { ...form, weight: Number(form.weight), department_id: Number(form.department_id), assignee_id: Number(form.assignee_id), year: Number(form.year) } }); await onSaved(result.message); }
    catch (err) { setError(err); } finally { setBusy(false); }
  }
  return <form onSubmit={submit}><div className="modal-body"><ErrorBox error={error} />{!row && <><Field label="Extra-work title" required><input maxLength={500} required value={form.title} onChange={e => set('title', e.target.value)} /></Field><div className="form-grid"><Field label="Department" required><select required value={form.department_id} onChange={e => setForm({ ...form, department_id: e.target.value, assignee_id: '' })}><option value="">Select department</option>{data.departments.filter(department => data.recipients.some(person => same(person.department_id, department.id))).map(department => <option key={department.id} value={department.id}>{department.name}</option>)}</select></Field><Field label="Assign to" required><select required disabled={!form.department_id} value={form.assignee_id} onChange={e => set('assignee_id', e.target.value)}><option value="">Select a reporting person</option>{data.recipients.filter(person => same(person.department_id, form.department_id)).map(person => <option key={person.id} value={person.id}>{person.name}</option>)}</select></Field><Field label="Performance year" required><input type="number" min="2000" max="2100" required value={form.year} onChange={e => set('year', e.target.value)} /></Field><Field label="Deadline" required><input type="date" required value={form.due_date} onChange={e => set('due_date', e.target.value)} /></Field><Field label="Priority"><select value={form.priority} onChange={e => set('priority', e.target.value)}><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></Field></div><Field label="Instructions"><textarea rows={3} maxLength={2000} value={form.instructions} onChange={e => set('instructions', e.target.value)} /></Field></>}
    <Field label="Extra-work weight" required hint="Relative importance within extra work. For example, use 70 and 30 for two different responsibilities."><input type="number" min="0.01" max="100" step="0.01" required value={form.weight} onChange={e => set('weight', e.target.value)} /></Field>{row && <Field label="Reason for weight change" required><textarea rows={3} maxLength={1000} required value={form.reason} onChange={e => set('reason', e.target.value)} /></Field>}<p className="extra-work-note">Weights rebalance extra-work completion only. Approval and previous score snapshots are kept in history.</p></div><div className="modal-footer"><button type="button" className="btn btn-secondary" disabled={busy} onClick={onClose}>Cancel</button><button className="btn btn-primary" disabled={busy}><Save size={16} />{busy ? 'Saving...' : row ? 'Approve weight change' : 'Assign extra work'}</button></div></form>;
}
