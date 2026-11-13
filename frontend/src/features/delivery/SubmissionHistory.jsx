import { ArrowUpRight, CheckCircle2, ClipboardList, Clock3, FileText, Link2, RotateCcw, ShieldCheck } from 'lucide-react';
import { Avatar, Empty } from '../../components/ui.jsx';
import { dateLabel, statuses } from './deliveryFormat.js';
import './submission-history.css';

function evidenceLink(value) {
  try {
    const url = new URL(value);
    return ['https:', 'http:'].includes(url.protocol) ? url : null;
  } catch {
    return null;
  }
}

const presentation = decision => decision === 'approved'
  ? { label: 'Approved', Icon: CheckCircle2 }
  : decision === 'needs_changes' ? { label: 'Needs changes', Icon: RotateCcw }
    : { label: decision === 'pending' ? 'Awaiting review' : statuses[decision] || 'Recorded', Icon: Clock3 };

export default function SubmissionHistory({ rows, person, compact = false }) {
  const ordered = [...rows].sort((a, b) => Number(b.id) - Number(a.id));
  const latest = ordered[0];
  const changes = ordered.filter(row => row.decision === 'needs_changes').length;
  const current = latest && presentation(latest.decision);

  return <div className={`submission-history ${compact ? 'is-compact' : 'modal-body'}`}>
    {!latest ? <Empty icon={ClipboardList} title="No submissions yet" text="Submitted evidence and review decisions will appear here." /> : <>
      {!compact && <div className={`history-summary history-${latest.decision}`}>
        <span className="history-summary-symbol"><current.Icon size={23} aria-hidden="true" /></span>
        <div className="history-summary-current"><small>LATEST SUBMISSION</small><strong>{current.label}</strong></div>
        <div className="history-summary-count"><strong>{ordered.length}</strong><span>submission{ordered.length === 1 ? '' : 's'}</span></div>
        {changes > 0 && <div className="history-summary-count"><strong>{changes}</strong><span>change request{changes === 1 ? '' : 's'}</span></div>}
      </div>}
      {!compact && <div className="history-section-label"><span>SUBMISSION TIMELINE</span><small>Most recent first</small></div>}
      <ol className="history-timeline" aria-label="Submission and review timeline">
        {ordered.map((row, index) => {
          const { label, Icon } = presentation(row.decision);
          const link = evidenceLink(row.evidence_url);
          return <li key={row.id} className={`history-entry history-${row.decision}`}>
            {!compact && <span className="history-timeline-node"><Icon size={16} aria-hidden="true" /></span>}
            <article className="history-card" aria-label={`Submission ${row.id}`}>
              <header className="history-card-header"><div><h3>Submission #{row.id}</h3>{!compact && index === 0 && <span className="history-latest">Latest</span>}</div><span className="history-status"><Icon size={13} aria-hidden="true" />{label}</span></header>
              <div className="history-submitter">
                {row.submitted_by && <><Avatar name={person(row.submitted_by)} small /><strong>{person(row.submitted_by)}</strong><span className="history-meta-dot" /></>}
                <span><Clock3 size={12} aria-hidden="true" />Submitted {dateLabel(row.submitted_at)}</span>
              </div>
              {row.timeliness_submitted_at && <div className="history-submitter"><span>Timing date: {dateLabel(row.timeliness_submitted_at)}</span><span>Deadline: {row.timeliness_due_date?.slice(0, 10) || 'Not assigned'}</span></div>}
              {row.decision === 'needs_changes' && <div className="history-submitter"><span>{row.retain_timeliness ? 'Clarification: original timing date retained' : 'Incomplete work: timing uses the next submission'}</span></div>}
              <section className="history-evidence"><h4><FileText size={14} aria-hidden="true" />Work delivered</h4><p>{row.evidence}</p></section>
              {link && <a className="history-evidence-link" href={link.href} target="_blank" rel="noopener noreferrer"><span className="history-link-icon"><Link2 size={17} aria-hidden="true" /></span><span><strong>Open supporting evidence</strong><small>{link.hostname}</small></span><ArrowUpRight size={17} aria-hidden="true" /><span className="history-screen-reader"> (opens in a new tab)</span></a>}
              {row.reviewed_by || row.review_note || row.decision === 'approved' ? <div className="history-review">
                <span className="history-review-symbol"><ShieldCheck size={17} aria-hidden="true" /></span>
                <div className="history-review-copy"><div className="history-review-heading"><strong>{row.reviewed_by ? person(row.reviewed_by) : row.decision === 'approved' ? 'Completion confirmed' : 'Review note'}</strong><span>{row.reviewed_at ? dateLabel(row.reviewed_at) : ''}</span></div><small>{row.reviewed_by ? 'Reviewer decision' : 'Recorded confirmation'}</small><p>{row.review_note || (row.decision === 'approved' ? 'Approved without an additional comment.' : 'No review comment recorded.')}</p></div>
              </div> : row.decision === 'pending' && <div className="history-awaiting"><Clock3 size={15} aria-hidden="true" /><span>Waiting for the reviewer’s decision</span></div>}
            </article>
          </li>;
        })}
      </ol>
    </>}
  </div>;
}
