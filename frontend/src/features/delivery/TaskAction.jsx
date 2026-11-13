import { useState } from 'react';
import { CheckCircle2, ChevronRight, FileCheck2, Link2, LoaderCircle, Send, Target, UserCheck } from 'lucide-react';
import { request } from '../../services/api.js';
import { ErrorBox, Field } from '../../components/ui.jsx';
import { pct } from './deliveryFormat.js';
import SubmissionHistory from './SubmissionHistory.jsx';
import './task-dialogs.css';

export default function TaskAction({ kind, task, submissions, person, onClose, onSaved, apiBase = '/delivery' }) {
  const [progress, setProgress] = useState(task.reported_progress), [note, setNote] = useState(''), [evidence, setEvidence] = useState(''), [url, setUrl] = useState(''), [decision, setDecision] = useState('approved');
  const [correctionType, setCorrectionType] = useState('incomplete');
  const [deadline, setDeadline] = useState(task.timeliness?.due_date || task.due_date || task.parent?.due_date || '');
  const [error, setError] = useState(null), [busy, setBusy] = useState(false);
  async function submit(e) {
    e.preventDefault(); setBusy(true); setError(null);
    try {
      if (kind === 'deadline') { await request(`${apiBase}/tasks/${task.id}/deadline`, { method: 'PUT', body: { version: Number(task.version), due_date: deadline, reason: note } }); await onSaved('Deadline extension approved and recorded.'); return; }
      if (kind === 'progress') await request(`${apiBase}/tasks/${task.id}/progress`, { method: 'PUT', body: { version: Number(task.version), reported_progress: Number(progress), progress_note: note } });
      else if (kind === 'submit') { const result = await request(`${apiBase}/tasks/${task.id}/submit`, { method: 'POST', body: { version: Number(task.version), evidence, evidence_url: url || null } }); await onSaved(result.message); return; }
      else { if (!submissions[0]) throw new Error('No pending submission was found. Refresh the workspace.'); await request(`${apiBase}/submissions/${submissions[0].id}/review`, { method: 'POST', body: { version: Number(task.version), decision, review_note: note || null, correction_type: correctionType } }); }
      await onSaved(kind === 'progress' ? 'Reported progress saved.' : kind === 'submit' ? 'Work submitted for review.' : decision === 'approved' ? 'Task approved. Confirmed completion recalculated.' : 'Changes requested. The owner can revise and resubmit.');
    } catch (err) { setError(err); } finally { setBusy(false); }
  }
  const progressValue = Math.min(100, Math.max(0, Number(progress || 0)));
  return <form className="delivery-dialog-form" onSubmit={submit}>
    <div className="modal-body delivery-action-body"><ErrorBox error={error} />
      <div className="delivery-action-context"><span><UserCheck size={16} /><span><small>{task.self_confirm ? 'COMPLETION APPROVAL' : 'REVIEWER'}</small><strong>{task.self_confirm ? 'HOF self confirmation' : person(task.reviewer_id)}</strong></span></span><span><Target size={16} /><span><small>PARENT WORK CONTRIBUTION</small><strong>{pct(task.weight)}</strong></span></span></div>
      {kind === 'deadline' ? <>
        <Field label="New deadline" required hint={`Current deadline: ${task.timeliness?.due_date || task.due_date || task.parent?.due_date || 'Not set'}`}><input type="date" required value={deadline} onChange={event => setDeadline(event.target.value)} /></Field>
        <Field label="Reason for extension" required><textarea rows={3} maxLength={1000} required value={note} onChange={event => setNote(event.target.value)} /></Field>
        <p className="form-note">The approved deadline and your reason are recorded in the audit history. Completed tasks keep their original timing score.</p>
      </> : kind === 'progress' ? <>
        <div className="delivery-progress-picker"><div className="delivery-picker-heading"><span><Target size={16} />REPORTED PROGRESS</span><span>{Number(task.confirmed_progress || 0)}% confirmed</span></div><div className="delivery-picker-value"><strong>{Number(progressValue.toFixed(2))}<span>%</span></strong><span>Work completed so far</span></div><input aria-label="Adjust reported progress" type="range" min="0" max="100" step="0.01" value={progressValue} onChange={e => setProgress(e.target.value)} /><div className="delivery-progress-presets">{[0, 25, 50, 75, 100].map(value => <button type="button" key={value} aria-pressed={Number(progress) === value} onClick={() => setProgress(value)}>{value}%</button>)}</div></div>
        <Field label="Reported progress (%)" required hint="Enter an exact percentage, or use the slider above."><input type="number" min="0" max="100" step="0.01" required value={progress} onChange={e => setProgress(e.target.value)} /></Field>
        <Field label="Progress update" required><textarea rows={4} required maxLength={2000} value={note} onChange={e => setNote(e.target.value)} placeholder="What has been completed? Mention any remaining work or blockers." /></Field><div className="form-note delivery-dialog-guidance"><CheckCircle2 size={18} /><span>Saving updates your reported progress. {task.self_confirm ? 'Confirm completion separately with evidence when the work is finished.' : 'Confirmed completion changes after your reviewer approves submitted work.'}</span></div>
      </> : kind === 'submit' ? <>
        <div className="delivery-submit-steps"><span className="is-current"><span>1</span>Describe delivery</span><ChevronRight size={14} /><span><span>2</span>{task.self_confirm ? 'Confirm completion' : 'Reviewer approval'}</span></div>
        <Field label="Evidence / completed work summary" required hint="Describe the result and how it can be verified."><textarea rows={5} required maxLength={5000} value={evidence} onChange={e => setEvidence(e.target.value)} placeholder="• What was delivered?
• What checks were completed?
• Where can the result be verified?" /></Field>
        <div className="delivery-evidence-link"><span className="delivery-link-heading"><Link2 size={17} />Supporting evidence</span><Field label="Evidence link (optional)" hint="Use an https:// or http:// link accessible to your reviewer."><input type="url" maxLength={2000} value={url} onChange={e => setUrl(e.target.value)} placeholder="https://…" /></Field></div>
        <div className="form-note delivery-dialog-guidance"><FileCheck2 size={20} /><span><strong>{task.self_confirm ? 'Confirm your completed work' : 'Ready for review'}</strong>{task.self_confirm ? 'Confirming records your evidence and adds this work’s agreed contribution immediately.' : 'Submit evidence to your assigning reviewer. Approval adds this work’s agreed contribution to its parent.'}</span></div>
      </> : <>
        <div className="delivery-review-evidence"><SubmissionHistory rows={submissions.slice(0, 1)} person={person} compact /></div><Field label="Review decision" required><select value={decision} onChange={e => setDecision(e.target.value)}><option value="approved">Approve completed work</option><option value="needs_changes">Request changes</option></select></Field>{decision === 'needs_changes' && <Field label="Reason for correction" required><select value={correctionType} onChange={event => setCorrectionType(event.target.value)}><option value="incomplete">Incomplete work - use resubmission date</option><option value="clarification">Clarification only - keep original submission date</option></select></Field>}<Field label="Review comments" required={decision === 'needs_changes'}><textarea rows={4} required={decision === 'needs_changes'} maxLength={2000} value={note} onChange={e => setNote(e.target.value)} placeholder="State the changes needed, or add an approval note." /></Field><div className="form-note delivery-dialog-guidance"><FileCheck2 size={18} /><span>Approving adds this task’s {pct(task.weight)} share to its parent work. This decision is recorded with your identity and time.</span></div>
      </>}
    </div>
    <div className="modal-footer"><span className="delivery-footer-note"><CheckCircle2 size={15} />{kind === 'deadline' ? 'Deadline change recorded with your identity' : kind === 'progress' ? 'Reported progress update' : task.self_confirm ? 'Evidence recorded on confirmation' : 'Evidence supports approval'}</span><button className="btn btn-secondary" type="button" disabled={busy} onClick={onClose}>Cancel</button><button className="btn btn-primary" disabled={busy}>{busy ? <LoaderCircle className="spin" size={16} /> : kind === 'submit' ? <Send size={16} /> : <CheckCircle2 size={16} />}{kind === 'deadline' ? 'Approve extension' : kind === 'progress' ? 'Save progress' : kind === 'submit' ? (task.self_confirm ? 'Confirm completion' : 'Submit for review') : 'Save review decision'}</button></div>
  </form>;
}
