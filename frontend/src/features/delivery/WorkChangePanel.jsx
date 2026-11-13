import { useEffect, useRef, useState } from 'react';
import { ArrowRightLeft, Plus } from 'lucide-react';
import { Badge, Empty, ErrorBox, Field, Modal } from '../../components/ui.jsx';
import { request } from '../../services/api.js';
import './work-changes.css';

const labels = { pending: 'Awaiting decision', replace: 'Replacement approved', continue_both: 'Continue both', extend_deadline: 'Deadline extended', reassign: 'Original work reassigned', rejected: 'Request declined', cancelled: 'Withdrawn' };
const initial = { requests: [], original_tasks: [], extra_assignments: [], reassignment_people: [], people: [], departments: [], read_only: true };
const same = (a, b) => String(a) === String(b);
const percentage = fraction => `${Number((Number(fraction) * 100).toFixed(2))}%`;

export default function WorkChangePanel({ notificationTarget, onChanged, refreshKey, year = 'all', department = 'all', query = '' }) {
  const [data, setData] = useState(initial), [loading, setLoading] = useState(true), [error, setError] = useState(null), [message, setMessage] = useState(''), [modal, setModal] = useState(null);
  const opened = useRef(null);
  const scoped = row => (year === 'all' || same(row.year, year)) && (department === 'all' || same(row.department_id, department));
  const rows = data.requests.filter(row => scoped(row) && `${row.task_title} ${row.extra_title} ${row.assignee_name}`.toLowerCase().includes(query.toLowerCase()));
  const scopedData = { ...data, original_tasks: data.original_tasks.filter(scoped) };
  async function load() {
    try { const result = await request('/work-changes/workspace'); setData(result); setError(null); return result; }
    catch (err) { setError(err); } finally { setLoading(false); }
  }
  useEffect(() => { load().then(result => {
    if (!result || !notificationTarget?.work_change_id || opened.current === notificationTarget.token) return;
    opened.current = notificationTarget.token;
    const row = result.requests.find(row => same(row.id, notificationTarget.work_change_id));
    if (row) setModal({ kind: row.can_decide ? 'decide' : 'history', row });
  }); }, [notificationTarget?.token, refreshKey]);
  async function saved(text) { setModal(null); setMessage(text); await load(); await onChanged(); }
  async function cancel(row) {
    try { const result = await request(`/work-changes/${row.id}/cancel`, { method: 'POST', body: {} }); await saved(result.message); }
    catch (err) { setError(err); }
  }
  return <section className="panel work-change-panel">
    <div className="panel-heading"><div><h2><ArrowRightLeft size={18} />Work-change requests</h2><p>Both responsibilities remain active until the original reviewer approves a change.</p></div>{!data.read_only && <button className="btn btn-primary" onClick={() => setModal({ kind: 'create' })}><Plus size={16} />Request work change</button>}</div>
    <ErrorBox error={error} />{message && <p className="extra-work-notice" role="status">{message}</p>}
    {loading ? <p className="work-change-note">Loading requests...</p> : !rows.length ? <Empty title="No work-change requests" text="If urgent extra work prevents planned work, request a decision from the original reviewer." /> : rows.map(row => <article className="work-change-row" key={row.id}>
      <div><Badge tone={row.decision === 'pending' ? 'draft' : row.decision === 'replace' ? 'mint' : 'neutral'}>{labels[row.decision]}</Badge><h3>{row.task_title}</h3><p>Extra work: <strong>{row.extra_title}</strong></p><p>{row.assignee_name} · Reviewer: {row.reviewer_name} · {row.year}</p><small>{row.approved_fraction ? `Approved replacement: ${percentage(row.approved_fraction)}` : `Requested replacement: ${percentage(row.requested_fraction)}`}</small><p>{row.reason}</p>{row.decision_note && <p className="work-change-decision">Decision: {row.decision_note}</p>}</div>
      <div className="work-change-actions">{row.can_decide && <button className="btn btn-primary" onClick={() => setModal({ kind: 'decide', row })}>Review work change</button>}{row.can_cancel && <button className="btn btn-secondary" onClick={() => cancel(row)}>Withdraw request</button>}<button className="text-button" onClick={() => setModal({ kind: 'history', row })}>Decision history</button></div>
    </article>)}
    {modal && <Modal title={modal.kind === 'create' ? 'Request work change' : modal.kind === 'decide' ? 'Review work change' : 'Work-change history'} subtitle={modal.row?.task_title} onClose={() => setModal(null)}>
      {modal.kind === 'history' ? <History row={modal.row} onClose={() => setModal(null)} /> : <WorkChangeForm data={scopedData} row={modal.row} onClose={() => setModal(null)} onSaved={saved} />}
    </Modal>}
  </section>;
}

function History({ row, onClose }) {
  const snapshot = JSON.parse(row.snapshot);
  return <><div className="modal-body"><Badge>{labels[row.decision]}</Badge><p className="work-change-note">Requested: {String(row.created_at).slice(0, 16)} UTC · {row.assignee_name}</p><p>{row.reason}</p><div className="form-note work-change-note"><strong>Original work retained</strong><p>{snapshot.original.title} · {percentage(snapshot.original.weight)} of parent work · {Number(snapshot.original.confirmed_progress)}% originally confirmed</p><p>Extra work: {snapshot.extra.title}</p></div>{row.decided_at && <><p>Decided by {row.reviewer_name} · {String(row.decided_at).slice(0, 16)} UTC</p><p>{row.decision_note}</p>{snapshot.decision && <p className="work-change-note">Approved work completion of the original activity: {Number(snapshot.decision.before.assignment.confirmed_progress)}% → {Number(snapshot.decision.after_assignment.confirmed_progress)}%. This records completion at decision time; subsequent approved delivery updates it live.</p>}</>}<p className="work-change-note">KPI targets and monthly outcome evaluation remain separate. This history does not calculate salary.</p></div><div className="modal-footer"><button className="btn btn-secondary" onClick={onClose}>Close</button></div></>;
}

function WorkChangeForm({ data, row, onClose, onSaved }) {
  const [form, setForm] = useState({ task_id: '', extra_assignment_id: '', fraction: row ? Number(row.requested_fraction) * 100 : 100, reason: '', decision: 'continue_both', decision_note: '', due_date: '', assignee_id: '' });
  const [error, setError] = useState(null), [busy, setBusy] = useState(false);
  const set = (key, value) => setForm(previous => ({ ...previous, [key]: value }));
  const task = data.original_tasks.find(task => same(task.id, form.task_id));
  const extras = data.extra_assignments.filter(extra => task && same(extra.assignee_id, task.assignee_id) && same(extra.department_id, task.department_id) && same(extra.year, task.year));
  const people = data.reassignment_people.filter(person => same(person.department_id, row?.department_id) && !same(person.id, row?.assignee_id));
  async function submit(event) {
    event.preventDefault(); setBusy(true); setError(null);
    try {
      const payload = row ? { version: Number(row.version), task_version: Number(row.task_version), extra_version: Number(row.extra_version), decision: form.decision, decision_note: form.decision_note,
        ...(form.decision === 'replace' ? { fraction: Number(form.fraction) / 100 } : {}), ...(form.decision === 'extend_deadline' ? { due_date: form.due_date } : {}), ...(form.decision === 'reassign' ? { assignee_id: Number(form.assignee_id) } : {}) }
        : { task_id: Number(form.task_id), task_version: Number(task?.version), extra_assignment_id: Number(form.extra_assignment_id), requested_fraction: Number(form.fraction) / 100, reason: form.reason };
      const result = await request(row ? `/work-changes/${row.id}/decide` : '/work-changes', { method: 'POST', body: payload });
      await onSaved(result.message);
    } catch (err) { setError(err); } finally { setBusy(false); }
  }
  return <form onSubmit={submit}><div className="modal-body"><ErrorBox error={error} />{row ? <><div className="form-note work-change-note"><strong>{row.assignee_name}</strong><p>Original: {row.task_title}</p><p>Extra work: {row.extra_title}</p><p>{row.reason}</p></div><Field label="Reviewer decision" required><select value={form.decision} onChange={event => set('decision', event.target.value)}><option value="continue_both">Continue both responsibilities</option><option value="replace">Replace with extra work</option><option value="extend_deadline">Extend original deadline</option><option value="reassign">Reassign unstarted original work</option><option value="rejected">Decline request</option></select></Field></> : <><Field label="Original planned task" required><select required value={form.task_id} onChange={event => setForm(previous => ({ ...previous, task_id: event.target.value, extra_assignment_id: '' }))}><option value="">Select original work</option>{data.original_tasks.map(task => <option key={task.id} value={task.id}>{task.title} · {data.people.find(person => same(person.id, task.assignee_id))?.name} · {task.year}</option>)}</select></Field><Field label="Urgent extra work" required hint="Only extra work for the same person, department and year can be selected."><select required disabled={!task} value={form.extra_assignment_id} onChange={event => set('extra_assignment_id', event.target.value)}><option value="">Select extra work</option>{extras.map(extra => <option key={extra.id} value={extra.id}>{extra.title}</option>)}</select></Field>{task && !extras.length && <p className="work-change-note">No eligible extra work is available. Assign the extra responsibility first.</p>}<Field label="Why can both responsibilities not be completed?" required><textarea required maxLength={2000} rows={4} value={form.reason} onChange={event => set('reason', event.target.value)} /></Field></>}
    {(!row || form.decision === 'replace') && <Field label={row ? 'Approved replacement (%)' : 'Requested replacement (%)'} required hint="100 replaces the whole task. A smaller share keeps the remaining original work active."><input required type="number" min="0.01" max="100" step="0.01" value={form.fraction} onChange={event => set('fraction', event.target.value)} /></Field>}
    {row && form.decision === 'extend_deadline' && <Field label="New original deadline" required><input type="date" required value={form.due_date} onChange={event => set('due_date', event.target.value)} /></Field>}
    {row && form.decision === 'reassign' && <Field label="New original task owner" required hint="The owner must be eligible in the existing reporting branch. Work that has started cannot be reassigned here."><select required value={form.assignee_id} onChange={event => set('assignee_id', event.target.value)}><option value="">Select a direct report</option>{people.map(person => <option key={person.id} value={person.id}>{person.name}</option>)}</select></Field>}
    {row && <Field label="Decision reason" required><textarea required maxLength={2000} rows={4} value={form.decision_note} onChange={event => set('decision_note', event.target.value)} /></Field>}
    <p className="form-note work-change-note">A request does not change your score. Only an approved replacement transfers the agreed contribution; completing both remains the default.</p></div><div className="modal-footer"><button type="button" disabled={busy} className="btn btn-secondary" onClick={onClose}>Cancel</button><button disabled={busy || (!row && !task)} className="btn btn-primary">{busy ? 'Saving...' : row ? 'Save work-change decision' : 'Send work-change request'}</button></div></form>;
}
