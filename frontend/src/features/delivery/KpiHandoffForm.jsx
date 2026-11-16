import { useState } from 'react';
import { ArrowRight, LoaderCircle, Send } from 'lucide-react';
import { Empty, ErrorBox, Field } from '../../components/ui.jsx';
import { request } from '../../services/api.js';
import { same } from './deliveryFormat.js';
import WorkSource from './WorkSource.jsx';

export default function KpiHandoffForm({ kpi, people, onClose, onSaved }) {
  const managers = people.filter(person => same(person.department_id, kpi.department_id) && person.responsibility === 'manager');
  const [assignee, setAssignee] = useState('');
  const [due, setDue] = useState(() => kpi.activities.map(row => row.assignment?.due_date?.slice(0, 10) || '').filter(Boolean).sort().at(-1) || '');
  const [instructions, setInstructions] = useState(''), [error, setError] = useState(null), [busy, setBusy] = useState(false);
  async function submit(event) {
    event.preventDefault(); setBusy(true); setError(null);
    try {
      const result = await request('/delivery/kpi-handoffs', { method: 'POST', body: { source_kind: kpi.handoff_kind, source_id: kpi.source_id, version: Number(kpi.delegation.version), assignee_id: Number(assignee), due_date: due || null, instructions: instructions || null } });
      await onSaved(result.message);
    } catch (err) { setError(err); } finally { setBusy(false); }
  }
  return <form onSubmit={submit}><div className="modal-body kpi-handoff-form"><ErrorBox error={error} />
    <div className="linked-kpa-mapping"><span>{kpi.kpi_title}</span><ArrowRight size={17} /><span>Next manager’s linked KPA</span></div>
    <WorkSource row={kpi} activity={false} />
    <p className="kpi-handoff-note">All {kpi.activities.length} activities go to this manager together. They can do the work, divide activities among their team, or assign the full KPI to their next reporting manager.</p>
    {!managers.length ? <Empty title="No reporting managers available" text="An administrator must connect a manager directly below you in this department first." /> : <>
      <Field label="Assign full KPI to" required><select required value={assignee} disabled={busy} onChange={event => setAssignee(event.target.value)}><option value="">Select a reporting manager</option>{managers.map(person => <option key={person.id} value={person.id}>{person.employee_number} · {person.name}{person.designation ? ` · ${person.designation}` : ''}</option>)}</select></Field>
      <Field label="KPI assignment deadline"><input type="date" value={due} disabled={busy} onChange={event => setDue(event.target.value)} /></Field>
      <Field label="Instructions"><textarea rows={3} maxLength={1000} disabled={busy} value={instructions} onChange={event => setInstructions(event.target.value)} /></Field>
    </>}
  </div><div className="modal-footer"><button type="button" className="btn btn-secondary" disabled={busy} onClick={onClose}>Cancel</button><button className="btn btn-primary" disabled={busy || !assignee}>{busy ? <LoaderCircle className="spin" size={16} /> : <Send size={16} />}Assign full KPI</button></div></form>;
}
