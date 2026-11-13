import { useState } from 'react';
import { ArrowRight, ClipboardList, LoaderCircle } from 'lucide-react';
import { ErrorBox, Field } from '../../components/ui.jsx';
import { request } from '../../services/api.js';

export default function PersonalPlanWork({ rows, reviewers, onSaved }) {
  const [selected, setSelected] = useState({}), [busy, setBusy] = useState(null), [error, setError] = useState(null);
  async function start(row, key) {
    setBusy(key); setError(null);
    try {
      const result = await request('/delivery/personal-plan/start', { method: 'POST', body: { plan_id: row.plan_id, activity_code: row.activity_code, source_revision: row.source_revision, version: row.version, reviewer_id: Number(selected[key]) } });
      await onSaved(result.message);
    } catch (err) { setError(err); } finally { setBusy(null); }
  }
  if (!rows.length) return null;
  return <section className="panel personal-plan-work"><div className="panel-heading"><div><h2>My published plan activities <span className="count-label">{rows.length}</span></h2><p>Choose a reviewer to start work.</p></div></div><ErrorBox error={error} />{rows.map(row => {
    const key = `${row.plan_id}:${row.activity_code}`;
    const candidates = reviewers.filter(person => String(person.department_id) === String(row.department_id));
    return <article key={key}><div><small>{row.plan_code} · {row.year}</small><h3><ClipboardList size={17} />{row.activity}</h3><p>{row.kpa_title} → {row.kpi_title}</p>{!candidates.length && <p className="personal-plan-review-warning">No reporting reviewer configured. Ask an administrator to connect your department reporting manager.</p>}</div><div><Field label={`Reviewer for ${row.activity}`} required><select disabled={busy !== null} value={selected[key] || ''} onChange={event => setSelected(previous => ({ ...previous, [key]: event.target.value }))}><option value="">Select reporting manager</option>{candidates.map(person => <option key={person.id} value={person.id}>{person.name}</option>)}</select></Field><button className="btn btn-primary" disabled={busy !== null || !selected[key]} onClick={() => start(row, key)}>{busy === key ? <LoaderCircle className="spin" size={16} /> : <ArrowRight size={16} />}Start activity</button></div></article>;
  })}</section>;
}
