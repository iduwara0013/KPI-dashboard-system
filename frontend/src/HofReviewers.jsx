import { useEffect, useState } from 'react';
import { request } from './api.js';
import { ErrorBox, Field } from './components.jsx';

export default function HofReviewers({ onBack }) {
  const [data, setData] = useState({ rows: [], reviewers: [] }), [error, setError] = useState(null), [busy, setBusy] = useState(false), [message, setMessage] = useState('');
  async function refresh() { try { setData(await request('/planning/hof-reviewers')); } catch (err) { setError(err); } }
  useEffect(() => { refresh(); }, []);
  async function save(e, row) {
    e.preventDefault(); setBusy(true); setError(null); setMessage('');
    try { await request(`/planning/hof-reviewers/${row.id}`, { method: 'POST', body: { version: row.version, reviewer_id: Number(new FormData(e.currentTarget).get('reviewer')) } }); await refresh(); setMessage('Reviewer saved. The HOF can submit work through Task reviews & delivery.'); }
    catch (err) { setError(err); } finally { setBusy(false); }
  }
  return <main className="hof-reviewers-page"><button className="btn btn-secondary" onClick={onBack}>Back to planning</button><div className="page-heading"><div><h1>HOF work reviewers</h1><p>Designate another active HOF to review own work. Changing reviewer starts a fresh submission; previous evidence and decisions remain in history.</p></div></div><ErrorBox error={error} />{message && <p role="status">{message}</p>}{!data.rows.length && <p>No HOF-owned activities have been assigned yet.</p>}{data.rows.map(row => <form className="panel modal-body" key={`${row.id}-${row.version}`} onSubmit={e => save(e, row)}><h2>{row.activity}</h2><p>{row.owner_name} · {row.activity_code}</p><Field label="Designated reviewer" required><select name="reviewer" defaultValue={row.task?.reviewer_id || ''} required disabled={busy}><option value="">Choose another HOF</option>{data.reviewers.filter(person => String(person.id) !== String(row.owner_id)).map(person => <option key={person.id} value={person.id}>{person.name}</option>)}</select></Field><button className="btn btn-primary" disabled={busy}>Save reviewer</button></form>)}</main>;
}

