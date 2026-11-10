import { ArrowRight, ClipboardList } from 'lucide-react';
import { Badge, Empty } from '../../components/ui.jsx';
import { dateLabel, statuses } from './deliveryFormat.js';

export default function SubmissionHistory({ rows, person, compact = false }) {
  return <div className={compact ? 'delivery-history' : 'modal-body delivery-history'}>{!rows.length ? <Empty icon={ClipboardList} title="No submissions yet" text="Submitted evidence and review decisions will appear here." /> : rows.map(row => <article key={row.id}><header><Badge tone={row.decision === 'approved' ? 'mint' : row.decision === 'needs_changes' ? 'draft' : 'neutral'}>{row.decision === 'pending' ? 'Awaiting review' : statuses[row.decision]}</Badge><small>Submission #{row.id} · {dateLabel(row.submitted_at)}</small></header><p>{row.evidence}</p>{row.evidence_url && <a href={row.evidence_url} target="_blank" rel="noopener noreferrer">Open supporting evidence <ArrowRight size={13} /></a>}{row.reviewed_by && <div><strong>{person(row.reviewed_by)} · {dateLabel(row.reviewed_at)}</strong><p>{row.review_note || 'Approved without an additional comment.'}</p></div>}{!row.reviewed_by && row.review_note && <p>{row.review_note}</p>}</article>)}</div>;
}
