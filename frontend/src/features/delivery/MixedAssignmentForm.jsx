import { useState } from 'react';
import { LoaderCircle, Target, UserCheck } from 'lucide-react';
import { request } from '../../services/api.js';
import { ErrorBox, Field } from '../../components/ui.jsx';
import { initialAssignmentDeadline, planAssignmentDeadline } from './assignmentDeadline.js';

const same = (a, b) => String(a) === String(b);
const pct = value => `${Number((Number(value) * 100).toFixed(2))}%`;
export default function MixedAssignmentForm({ kind, row, managers, employees, session, onClose, onSaved }) {
  const kpi = kind === 'kpi';
  const record = kpi ? row.delegation : row.assignment;
  const initialRole = same(record?.assignee_id, session.id) ? 'own' : employees.some(person => same(person.id, record?.assignee_id) && same(person.department_id, row.department_id)) ? 'employee' : 'manager';
  const [role, setRole] = useState(initialRole), [inherit, setInherit] = useState(false);
  const planDeadline = planAssignmentDeadline(kind, row);
  const [form, setForm] = useState({ assignee_id: record?.assignee_id || '', due_date: initialAssignmentDeadline(kind, row), instructions: record?.instructions || '' });
  const [busy, setBusy] = useState(false), [error, setError] = useState(null);
  const eligible = (role === 'employee' ? employees : managers).filter(person => same(person.department_id, row.department_id));
  const recipient = inherit ? row.delegation.assignee_id : role === 'own' ? session.id : form.assignee_id;
  const covered = !kpi && row.delegation && same(row.delegation.assignee_id, recipient);
  const reset = record && (!same(record.assignee_id, recipient) || row.source_changed || (kpi && !row.valid));
  async function submit(e) {
    e.preventDefault(); setBusy(true); setError(null);
    try {
      await request(kpi ? '/hof/kpi-assignments' : '/hof/assignments', { method: 'POST', body: {
        plan_id: row.plan_id, ...(kpi ? { kpi_code: row.kpi_code } : { activity_code: row.activity_code, use_kpi_owner: inherit }),
        source_revision: row.source_revision, version: Number(record?.version || 0), assignee_id: Number(recipient), due_date: form.due_date || null,
        instructions: form.instructions,
      } });
      await onSaved(kpi ? 'KPI ownership saved. Individual activity overrides were preserved.' : 'Activity ownership saved.');
    } catch (err) { setError(err); } finally { setBusy(false); }
  }
  return <form onSubmit={submit}><div className="modal-body"><ErrorBox error={error} />
    <div className="hof-assignment-source"><small>{kpi ? 'FULL KPI' : 'INDIVIDUAL ACTIVITY'} · {kpi ? row.kpi_code : row.activity_code} · {row.year}</small><p>{row.kpa_title} → {row.kpi_title}</p><strong>{pct(row.personal_weight)} contribution to the HOF plan</strong></div>
    <div className="mixed-recipient-options" aria-label="Choose recipient type">{[['manager', 'Manager'], ['employee', 'Direct employee'], ['own', 'My own work']].map(([value, label]) => <button type="button" disabled={inherit} key={value} className={role === value ? 'selected' : ''} onClick={() => { setRole(value); setForm({ ...form, assignee_id: value === 'own' ? session.id : '' }); }}>{label}</button>)}</div>
    {!kpi && row.delegation && <label className="mixed-inherit"><input type="checkbox" checked={inherit} onChange={e => setInherit(e.target.checked)} />Use the KPI’s delegated owner for this activity</label>}
    {!inherit && role !== 'own' ? <Field label={role === 'employee' ? 'Direct employee' : 'Responsible manager'} required><select required value={form.assignee_id} onChange={e => setForm({ ...form, assignee_id: e.target.value })}><option value="">Choose a person in this department</option>{eligible.map(person => <option key={person.id} value={person.id}>{person.name} · {person.designation}</option>)}</select></Field> : <div className="form-note"><UserCheck size={18} /><span>{inherit ? 'Ownership follows the current KPI delegation.' : `My own work · ${session.name}`}</span></div>}
    {!inherit && role !== 'own' && !eligible.length && <div className="hof-review-note">No eligible {role === 'employee' ? 'employees' : 'reporting managers'} were found in this department. Ask an administrator to connect the reporting structure.</div>}
    <Field label="Assignment deadline" hint={record ? 'Your saved deadline is shown. You can change it.' : planDeadline ? (kpi ? 'Filled from the latest activity deadline in this KPI. You can change it.' : 'Filled from the activity deadline in the plan. You can change it.') : 'No activity deadline is set in the plan. Choose a date if needed.'}><input type="date" value={form.due_date} onChange={e => setForm({ ...form, due_date: e.target.value })} /></Field>
    <Field label="Instructions"><textarea rows={3} maxLength={1000} value={form.instructions} onChange={e => setForm({ ...form, instructions: e.target.value })} /></Field>
    {covered && <div className="form-note">This activity belongs to the same person’s delegated KPI. It is included in that linked KPA, so no second personal weight is added.</div>}
    {kpi && <div className="form-note"><Target size={18} /><span>The recipient takes responsibility for the full KPI. Unassigned activities follow that owner. Existing individual activity assignments stay visible as overrides in the same work tree.</span></div>}
    {role === 'employee' && <div className="form-note">Direct employee work appears in My tasks. Completed work is submitted to the HOF for review.</div>}
    {reset && <div className="hof-review-note">Changing ownership or confirming changed source details starts a new task cycle for affected inherited work. Previous tasks and approvals remain in history.</div>}
  </div><div className="modal-footer"><button type="button" className="btn btn-secondary" disabled={busy} onClick={onClose}>Cancel</button><button className="btn btn-primary" disabled={busy}>{busy ? <LoaderCircle className="spin" size={16} /> : <UserCheck size={16} />}Save {kpi ? 'KPI' : 'activity'} assignment</button></div></form>;
}
