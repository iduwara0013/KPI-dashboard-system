import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, BarChart3, CalendarDays, CheckCircle2, Clock3, RefreshCw, Target } from 'lucide-react';
import { request } from '../../services/api.js';
import { Avatar, Badge, Brand, Empty, ErrorBox, Field, Modal } from '../../components/ui.jsx';
import './monthlyKpi.css';

const statuses = {
  pending_measurement: ['Pending measurement', 'neutral'],
  submitted: ['Awaiting review', 'blue'],
  needs_correction: ['Needs correction', 'amber'],
  approved: ['Approved', 'green'],
  superseded: ['Superseded', 'neutral'],
};
const number = new Intl.NumberFormat('en', { maximumFractionDigits: 4 });
const format = (value, unit) => value === null ? '—' : `${number.format(value)}${unit === 'percent' ? '%' : ` ${unit}`}`;
const gapText = (value, unit) => value === null ? '—' : `${value > 0 ? '+' : ''}${format(value, unit === 'percent' ? 'pp' : unit)}`;
const context = row => ({ plan_id: row.plan_id, kpi_code: row.kpi_code, context: row.context });

export default function MonthlyKpiWorkspace({ session, embedded = false, notificationTarget }) {
  const [period, setPeriod] = useState('');
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [refresh, setRefresh] = useState(0);
  const [filter, setFilter] = useState('all');
  const [department, setDepartment] = useState('all');
  const [action, setAction] = useState(null);
  const [notice, setNotice] = useState('');
  useEffect(() => {
    let current = true;
    request(`/kpi-results/workspace${period ? `?period=${period}` : ''}`).then(result => {
      if (current) setData({ ...result, notification_token: notificationTarget?.token });
    }).catch(err => {
      if (current) setError(err);
    });
    return () => { current = false; };
  }, [period, refresh]);

  function reload() {
    setData(null);
    setError(null);
    setRefresh(count => count + 1);
  }

  function changePeriod(value) {
    if (!value || value === (period || data?.period)) return;
    setData(null);
    setError(null);
    setNotice('');
    setDepartment('all');
    setPeriod(value);
  }

  const openedNotification = useRef(null);
  useEffect(() => {
    if (notificationTarget?.workspace !== 'results') return;
    setPeriod(notificationTarget.period); setData(null); setError(null); setFilter('all'); setDepartment('all'); setAction(null);
    setRefresh(count => count + 1);
  }, [notificationTarget]);
  useEffect(() => {
    if (!data || data.notification_token !== notificationTarget?.token || notificationTarget?.workspace !== 'results' || data.period !== notificationTarget.period || openedNotification.current === notificationTarget.token) return;
    openedNotification.current = notificationTarget.token;
    const row = data.rows.find(row => String(row.plan_id) === String(notificationTarget.plan_id) && row.kpi_code === notificationTarget.kpi_code);
    if (!row) { setNotice('This KPI is no longer available in your current scope.'); return; }
    const currentAttempt = Number(row.attempt) === Number(notificationTarget.attempt);
    setAction({ row, kind: currentAttempt && row.can_review ? 'review' : currentAttempt && row.status === 'needs_correction' && row.can_submit ? 'submit' : 'history' });
  }, [data, notificationTarget]);

  const rows = data?.rows ?? [];
  const departments = [...new Set(rows.map(row => row.department))].sort();
  const scopedRows = rows.filter(row => department === 'all' || row.department === department);
  const visibleRows = scopedRows.filter(row => filter === 'all' || row.status === filter);
  const awaiting = scopedRows.filter(row => row.status === 'submitted').length;
  const approved = scopedRows.filter(row => row.status === 'approved').length;
  const missing = scopedRows.filter(row => row.status === 'pending_measurement').length;

  const Content = embedded ? 'div' : 'main';
  return <div className={`monthly-kpi ${embedded ? 'workspace-embedded' : ''}`}>
    {!embedded && <header className="monthly-kpi-header">
      <a className="brand" href="/" aria-label="CPSTL workspace"><Brand /></a>
      <div><a className="btn btn-secondary" href="/"><ArrowLeft size={16} />Back to workspace</a><Avatar name={session.name} small /></div>
    </header>}
    <Content>
      <div className="page-heading page-heading-compact">
        <div><h1>Monthly KPI Results</h1></div>
        {session.system_role === 'md' && <Badge>Read-only access</Badge>}
      </div>
      <div className="monthly-kpi-toolbar">
        <Field label="Reporting month"><input type="month" aria-label="Reporting month" min="2000-01" max="2100-12" value={period || data?.period || ''} onChange={event => changePeriod(event.target.value)} /></Field>
        <Field label="Department"><select value={department} onChange={event => setDepartment(event.target.value)}><option value="all">All visible departments</option>{departments.map(name => <option key={name}>{name}</option>)}</select></Field>
        <Field label="Status"><select value={filter} onChange={event => setFilter(event.target.value)}><option value="all">All statuses</option>{Object.entries(statuses).filter(([key]) => key !== 'superseded').map(([key, [label]]) => <option key={key} value={key}>{label}</option>)}</select></Field>
        <button className="btn btn-secondary" onClick={reload}><RefreshCw size={16} />Refresh</button>
      </div>
      {notice && <p className="monthly-kpi-notice" role="status">{notice}</p>}
      <ErrorBox error={error} />
      {!data && !error && <p role="status">Loading monthly results…</p>}
      {data && <>
        {data.future && <p className="monthly-kpi-notice">This reporting month has yet to begin. Submission opens at the start of the month.</p>}
        <div className="monthly-kpi-summary">
          {[[Target, 'Monthly KPIs', scopedRows.length], [CalendarDays, 'Pending measurement', missing], [Clock3, 'Awaiting review', awaiting], [CheckCircle2, 'Approved results', approved]].map(([Icon, label, count]) => <div className="panel" key={label}><Icon size={20} /><div><strong>{count}</strong><small>{label}</small></div></div>)}
        </div>
        {visibleRows.length === 0 ? <section className="panel"><Empty icon={BarChart3} title={rows.length ? 'No results match the filters' : 'No monthly KPIs for this period'} text={rows.length ? 'Choose a different department or status.' : 'This workspace shows published KPIs with a Monthly monitoring frequency that you have access to.'} /></section> :
          <section className="panel">
            <div className="panel-heading"><div><h2>Results register</h2><p>Positive gap = better than target</p></div></div>
            <div className="table-scroll"><table className="monthly-kpi-table">
              <thead><tr><th>KPI / Department</th><th>Owner / Reviewer</th><th>Target</th><th>Actual</th><th>Gap</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>{visibleRows.map(row => {
                const [label, tone] = statuses[row.status];
                return <tr key={`${row.plan_id}:${row.kpi_code}`}>
                  <td><strong>{row.title}</strong><small>{row.department} · {row.kpa}</small><small>{row.plan_code} · {row.kpi_code}</small></td>
                  <td>{row.archived ? <Badge>Archived KPI</Badge> : <><strong>{row.reporter || 'Owner needs assignment'}</strong><small>{row.self_confirm ? 'HOF confirms the result' : row.reviewer ? `Reviewer: ${row.reviewer}` : 'Reviewer needs assignment'}</small></>}</td>
                  <td><strong>{format(row.target, row.unit)}</strong><small>{row.direction === 'lower' ? 'Lower is better' : 'Higher is better'}</small></td>
                  <td><strong>{format(row.actual, row.unit)}</strong></td>
                  <td><strong className={row.gap < 0 ? 'kpi-gap-negative' : row.gap > 0 ? 'kpi-gap-positive' : ''}>{gapText(row.gap, row.unit)}</strong></td>
                  <td><Badge tone={tone}>{label}</Badge></td>
                  <td><div className="monthly-kpi-actions">
                    {row.can_submit && <button className="btn btn-primary btn-small" onClick={() => setAction({ row, kind: 'submit' })}>{row.attempt ? 'Submit new result' : 'Enter actual'}</button>}
                    {row.can_review && <button className="btn btn-primary btn-small" onClick={() => setAction({ row, kind: 'review' })}>Review result</button>}
                    {row.history.length > 0 && <button className="btn btn-secondary btn-small" onClick={() => setAction({ row, kind: 'history' })}>History</button>}
                  </div></td>
                </tr>;
              })}</tbody>
            </table></div>
          </section>}
      </>}
    </Content>
    {action && <ResultModal key={action.kind} {...action} month={Number(data.period.slice(5))} period={data.period}
      onClose={() => setAction(null)} onSaved={message => { setNotice(message); setAction(null); reload(); }} />}
  </div>;
}

function ResultModal({ row, kind, month, period, onClose, onSaved }) {
  const isCorrection = row.status === 'needs_correction';
  const [actual, setActual] = useState(isCorrection ? row.actual ?? '' : '');
  const [evidence, setEvidence] = useState(isCorrection ? row.history[0]?.evidence ?? '' : '');
  const [url, setUrl] = useState(isCorrection ? row.history[0]?.evidence_url ?? '' : '');
  const [decision, setDecision] = useState('approved');
  const [note, setNote] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const saving = useRef(false);
  const titles = { submit: row.self_confirm ? 'Confirm monthly result' : 'Submit monthly result', review: 'Review monthly result', history: 'Monthly result history' };
  async function submit(event) {
    event.preventDefault();
    if (saving.current) return;
    saving.current = true;
    setBusy(true);
    setError(null);
    const fields = { ...context(row), month, attempt: row.attempt };
    const body = kind === 'submit' ? { ...fields, actual_value: actual, evidence, evidence_url: url || null }
      : { ...fields, decision, review_note: note || null };
    try {
      const response = await request(`/kpi-results/${kind}`, { method: 'POST', body });
      onSaved(response.message);
    } catch (err) {
      setError(err);
    } finally {
      saving.current = false;
      setBusy(false);
    }
  }

  return <Modal title={titles[kind]} subtitle={`${row.title} · ${period}`} onClose={() => { if (!saving.current) onClose(); }}>
    {kind === 'history' ? <div className="modal-body monthly-kpi-history">{row.history.map(item => <Submission key={item.attempt} item={item} unit={row.unit} />)}</div> :
      <form onSubmit={submit}>
        <div className="modal-body">
          <ErrorBox error={error} />
          {kind === 'submit' && <>
            <p className="monthly-kpi-note">Published target for this result is <strong>{format(row.target, row.unit)}</strong>.</p>
            {row.status === 'needs_correction' && <p className="monthly-kpi-note"><strong>Requested correction</strong><br />{row.history[0].review_note}</p>}
            <Field label={`Actual value (${row.unit})`} required><input required type="number" step="0.0001" min="-9999999999999" max="9999999999999" value={actual} onChange={event => setActual(event.target.value)} /></Field>
            <Field label="Evidence summary" required hint="Include the source and how you measured this result."><textarea required rows={4} maxLength={2000} value={evidence} onChange={event => setEvidence(event.target.value)} /></Field>
            <Field label="Evidence link (optional)"><input type="url" maxLength={1000} value={url} onChange={event => setUrl(event.target.value)} /></Field>
          </>}
          {kind === 'review' && <>
            <p className="monthly-kpi-note">Target <strong>{format(row.target, row.unit)}</strong> · Gap <strong>{gapText(row.gap, row.unit)}</strong></p>
            <Submission item={row.history[0]} unit={row.unit} />
            <Field label="Decision" required><select value={decision} onChange={event => setDecision(event.target.value)}><option value="approved">Approve result</option><option value="needs_correction">Request correction</option></select></Field>
            <Field label="Review note" required={decision === 'needs_correction'}><textarea required={decision === 'needs_correction'} rows={3} maxLength={2000} value={note} onChange={event => setNote(event.target.value)} /></Field>
          </>}
        </div>
        <div className="modal-footer"><button className="btn btn-secondary" type="button" onClick={onClose} disabled={busy}>Cancel</button><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : kind === 'submit' ? (row.self_confirm ? 'Confirm result' : 'Submit for review') : 'Save decision'}</button></div>
      </form>}
  </Modal>;
}

function Submission({ item, unit }) {
  const [status, tone] = statuses[item.status];
  return <div className="monthly-kpi-submission">
    <div><strong>Attempt {item.attempt} · {format(item.actual, unit)}</strong><Badge tone={tone}>{status}</Badge></div>
    <small>Submitted by {item.submitted_by}{item.created_at && ` · ${item.created_at.slice(0, 10)}`}</small>
    {item.evidence && <p>{item.evidence}</p>}
    {item.evidence_url && <a href={item.evidence_url} target="_blank" rel="noopener noreferrer">Open evidence link ↗</a>}
    {item.reviewed_by && <small>Reviewed by {item.reviewed_by}{item.reviewed_at && ` · ${item.reviewed_at.slice(0, 10)}`}</small>}
    {item.review_note && <p>{item.review_note}</p>}
  </div>;
}
