import { ArrowUpRight, CalendarDays, Clock3, CheckCircle2 } from 'lucide-react';

export function deadlineDetails(value) {
  if (!value) return { label: 'No deadline set', days: null, tone: 'neutral' };
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Colombo', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
  const days = Math.round((Date.parse(value.slice(0, 10) + 'T00:00:00Z') - Date.parse(today + 'T00:00:00Z')) / 86400000);
  if (!Number.isFinite(days)) return { label: 'Deadline unavailable', days: null, tone: 'neutral' };
  return { days, label: days < 0 ? `${Math.abs(days)}d overdue` : days === 0 ? 'Due today' : `Due in ${days}d`, tone: days < 0 ? 'danger' : days <= 7 ? 'warning' : 'neutral' };
}

export default function WorkFocus({ items, title = 'Your attention, in the right place', subtitle = 'The next actions across your current work' }) {
  const rows = items.map(item => ({ ...item, deadline: deadlineDetails(item.due) })).sort((a, b) => (a.priority ?? (a.deadline.days !== null && a.deadline.days < 0 ? 0 : 3)) - (b.priority ?? (b.deadline.days !== null && b.deadline.days < 0 ? 0 : 3)) || (a.deadline.days ?? Infinity) - (b.deadline.days ?? Infinity)).slice(0, 4);
  return <section className="work-focus" aria-label="Priority work"><header><div><span className="eyebrow">ACTION CENTER</span><h2>{title}</h2><p>{subtitle}</p></div><span className="focus-count">{items.length} open {items.length === 1 ? 'item' : 'items'}</span></header>{!rows.length ? <div className="focus-clear"><CheckCircle2 size={21} /><div><strong>No outstanding actions in this view</strong><p>Approved work is kept in your work history.</p></div></div> : <div className="focus-list">{rows.map(item => <button key={item.id} className="focus-row" onClick={item.onOpen}><span className={`focus-row-icon ${item.deadline.tone}`}><Clock3 size={19} /></span><span className="focus-row-copy"><strong>{item.title}</strong><small>{item.meta}</small></span><span className="focus-row-facts"><span className={`focus-deadline ${item.deadline.tone}`}><CalendarDays size={12} />{item.deadline.label}</span><small>{item.status}</small></span><ArrowUpRight size={17} /></button>)}</div>}</section>;
}
