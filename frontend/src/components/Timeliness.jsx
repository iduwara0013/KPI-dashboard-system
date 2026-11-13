import { Clock3 } from 'lucide-react';
import './timeliness.css';

export default function Timeliness({ value, compact = false }) {
  const provisional = (value?.coverage || 0) < 99.995;
  const score = provisional ? value?.estimate : value?.score;
  const format = number => `${Number(Number(number).toFixed(1))}%`;
  return <div className={`timeliness ${compact ? 'timeliness-compact' : ''} ${score != null && score < 100 ? 'timeliness-late' : ''}`}>
    <span className="timeliness-label"><Clock3 size={15} />Timeliness</span>
    <strong>{score == null ? '—' : format(score)}</strong>
    <small>{score == null ? 'No scheduled work assessed' : provisional ? 'Provisional' : 'Approved work'}</small>
    {!compact && score != null && <span className="timeliness-detail">{value?.late_days != null ? value.late_days === 0 ? 'On time' : `${value.late_days} days late` : `${format(value.coverage || 0)} of scheduled weight assessed`}{value?.score != null && provisional ? ` · Approved: ${format(value.score)}` : ''}</span>}
    {value?.legacy && !compact && <span className="timeliness-detail">Historical submission · current recorded deadline</span>}
  </div>;
}
