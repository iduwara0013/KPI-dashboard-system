import { useState } from 'react';
import { Send } from 'lucide-react';
import { ErrorBox, Field } from '../../components/ui.jsx';
import { request } from '../../services/api.js';
import WorkSource from './WorkSource.jsx';
import { same } from './deliveryFormat.js';

export default function ActivityAssignmentForm({ parent, data, session, onClose, onSaved }) {
  const [owner, setOwner] = useState(String(session.id)), [busy, setBusy] = useState(false), [error, setError] = useState(null);
  async function submit(event) {
    event.preventDefault(); setBusy(true); setError(null);
    try {
      await request(`/delivery/${parent.task_branch ? 'tasks' : 'assignments'}/${parent.id}/tasks`, { method: 'PUT', body: { version: parent.version, tasks: [{ work_kind: 'activity', title: parent.activity, assignee_id: Number(owner), weight: 1, due_date: parent.due_date || null, instructions: parent.instructions || null }] } });
      await onSaved('Activity assigned. Its source KPA and KPI remain linked.');
    } catch (err) { setError(err); } finally { setBusy(false); }
  }
  return <form onSubmit={submit}><div className="modal-body"><ErrorBox error={error} /><WorkSource row={parent} /><Field label="Activity owner" required><select required disabled={busy} value={owner} onChange={event => setOwner(event.target.value)}><option value={session.id}>Keep with me · {session.name}</option>{data.employees.filter(person => same(person.department_id, parent.department_id)).map(person => <option key={person.id} value={person.id}>{person.employee_number} · {person.name}</option>)}</select></Field><p className="kpi-handoff-note">Assign this complete activity, or keep it yourself. Other activities stay with their existing owners.</p></div><div className="modal-footer"><button type="button" className="btn btn-secondary" disabled={busy} onClick={onClose}>Cancel</button><button className="btn btn-primary" disabled={busy}><Send size={16} />Save activity owner</button></div></form>;
}
