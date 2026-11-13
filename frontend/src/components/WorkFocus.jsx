import { ArrowUpRight, CalendarDays, Clock3, CheckCircle2, ListChecks, AlertCircle } from 'lucide-react';
import './work-focus.css';

export function deadlineDetails(value) {
  if (!value) return { label: 'No deadline set', days: null, tone: 'neutral' };
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Colombo', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
  const days = Math.round((Date.parse(value.slice(0, 10) + 'T00:00:00Z') - Date.parse(today + 'T00:00:00Z')) / 86400000);
  if (!Number.isFinite(days)) return { label: 'Deadline unavailable', days: null, tone: 'neutral' };
  return { days, label: days < 0 ? `${Math.abs(days)}d overdue` : days === 0 ? 'Due today' : `Due in ${days}d`, tone: days < 0 ? 'danger' : days <= 7 ? 'warning' : 'neutral' };
}

export default function WorkFocus({ items, title = 'Needs attention' }) {
  const rows = items.map(item => ({ ...item, deadline: deadlineDetails(item.due) })).sort((a, b) => (a.priority ?? (a.deadline.days !== null && a.deadline.days < 0 ? 0 : 3)) - (b.priority ?? (b.deadline.days !== null && b.deadline.days < 0 ? 0 : 3)) || (a.deadline.days ?? Infinity) - (b.deadline.days ?? Infinity)).slice(0, 4);
  return <section className="work-focus work-focus-premium" aria-label="Priority work">
    <header><span className="focus-heading-icon"><ListChecks size={24} /></span><div><h2>{title}</h2></div><span className="focus-count"><span />{items.length} open {items.length === 1 ? 'item' : 'items'}</span></header>
    {!rows.length ? <div className="focus-clear"><CheckCircle2 size={25} /><div><strong>No outstanding actions in this view</strong><p>Approved work is kept in your work history.</p></div></div> : <div className="focus-list">{rows.map(item => {
      const tone = item.priority === 0 ? 'danger' : item.priority === 1 ? 'warning' : item.deadline.tone;
      const Icon = tone === 'danger' ? AlertCircle : item.priority === 1 ? CheckCircle2 : Clock3;
      return <button key={item.id} className={`focus-row focus-row--${tone}`} onClick={item.onOpen}>
        <span className={`focus-row-icon ${tone}`}><Icon size={22} /></span>
        <span className="focus-row-copy"><strong>{item.title}</strong><small>{item.meta}</small></span>
        <span className="focus-row-facts"><span className={`focus-deadline ${item.deadline.tone}`}><CalendarDays size={14} />{item.deadline.label}</span>{item.due && item.deadline.days !== null && <time dateTime={item.due.slice(0, 10)}>{new Date(item.due.slice(0, 10) + 'T00:00:00Z').toLocaleDateString('en-GB', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' })}</time>}<small className={`focus-status ${tone}`}>{item.status}</small></span>
        <span className="focus-open">Open<ArrowUpRight size={17} /></span>
      </button>;
    })}</div>}
    {items.length > rows.length && <div className="focus-more">Showing the {rows.length} highest priority items of {items.length} open items.</div>}
  </section>;
}
