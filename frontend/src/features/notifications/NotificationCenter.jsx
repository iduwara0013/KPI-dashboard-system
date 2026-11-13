import { notificationPageSize, appendNotificationPage } from './notificationFeed.js';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Archive, ArchiveRestore, ArrowRight, Bell, CalendarClock, Check, CheckCheck, CircleAlert, ClipboardList, FileCheck2, Inbox, LoaderCircle, RefreshCw } from 'lucide-react';
import { request } from '../../services/api.js';
import { Empty, ErrorBox, Modal } from '../../components/ui.jsx';
import './notifications.css';

const presentation = {
  kpi_submission: ['Monthly result review', FileCheck2, 'amber'],
  kpi_approved: ['Monthly result approved', CheckCheck, 'green'],
  kpi_needs_correction: ['Monthly result correction', CircleAlert, 'red'],
  assignment: ['Work assigned', ClipboardList, 'blue'],
  submission: ['Review requested', FileCheck2, 'amber'],
  approved: ['Work approved', CheckCheck, 'green'],
  needs_changes: ['Changes requested', CircleAlert, 'red'],
  overdue: ['Overdue work', CalendarClock, 'red'],
  due_soon: ['Deadline approaching', CalendarClock, 'amber'],
};
const dateOf = value => new Date(/[Z+]|-\d\d:\d\d$/.test(value) ? value : value.replace(' ', 'T') + 'Z');
const dayKey = date => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Colombo' }).format(date);
function dayGroup(value) {
  const date = dateOf(value), today = new Date();
  if (dayKey(date) === dayKey(today)) return 'Today';
  today.setDate(today.getDate() - 1);
  return dayKey(date) === dayKey(today) ? 'Yesterday' : 'Earlier';
}
const dateLabel = value => dateOf(value).toLocaleString('en-GB', { timeZone: 'Asia/Colombo', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

export default function NotificationCenter({ user, paused, onOpen, locationKey }) {
  const [open, setOpen] = useState(false), [expanded, setExpanded] = useState(false), [anchor, setAnchor] = useState(null);
  const [items, setItems] = useState([]), [unread, setUnread] = useState(0), [next, setNext] = useState(null);
  const [view, setView] = useState('all'), [category, setCategory] = useState('all');
  const [error, setError] = useState(null), [busy, setBusy] = useState(false), [working, setWorking] = useState(false);
  const mounted = useRef(false), ticket = useRef(0), list = useRef(null), inFlight = useRef(false), loadedCount = useRef(0);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; ticket.current++; }; }, []);
  useEffect(() => { setAnchor(document.querySelector('.topbar-right')); }, [locationKey]);
  const refresh = useCallback(async (before = null, quiet = false) => {
    const current = ++ticket.current;
    inFlight.current = true;
    if (!quiet) setBusy(true);
    try {
      const params = new URLSearchParams({ view, category, limit: String(before ? 30 : notificationPageSize(loadedCount.current)) });
      if (before) params.set('before', before);
      const wanted = before ? 30 : Math.max(30, loadedCount.current);
      let result = await request(`/notifications?${params}`);
      let incoming = result.items;
      while (!before && incoming.length < wanted && result.next && mounted.current && current === ticket.current) {
        const nextCursor = result.next;
        params.set('before', String(nextCursor));
        params.set('limit', String(notificationPageSize(wanted - incoming.length)));
        result = await request(`/notifications?${params}`);
        incoming = appendNotificationPage(incoming, result.items);
        if (result.next === nextCursor) break;
      }
      if (!mounted.current || current !== ticket.current) return;
      setItems(previous => { const updated = before ? appendNotificationPage(previous, incoming) : incoming; loadedCount.current = updated.length; return updated; });
      setUnread(result.unread); setNext(result.next); setError(null);
    } catch (err) { if (mounted.current && current === ticket.current) setError(err); }
    finally { if (mounted.current && current === ticket.current) { inFlight.current = false; setBusy(false); } }
  }, [view, category]);
  useEffect(() => {
    if (paused) return;
    loadedCount.current = 0; setItems([]); setNext(null); refresh();
    if (list.current) list.current.scrollTop = 0;
    const poll = () => { if (document.visibilityState === 'visible' && !inFlight.current) refresh(null, true); };
    const timer = setInterval(poll, 30000);
    window.addEventListener('focus', poll);
    return () => { clearInterval(timer); window.removeEventListener('focus', poll); };
  }, [paused, refresh]);
  async function mark(item, navigate = false) {
    if (working || paused) return;
    setWorking(true); setError(null);
    try {
      const result = await request(item ? `/notifications/${item.id}/read` : '/notifications/read-all', { method: 'POST' });
      if (!mounted.current) return;
      setUnread(result.unread);
      setItems(previous => previous.map(row => !item || row.id === item.id ? { ...row, read_at: row.read_at || new Date().toISOString() } : row));
      if (navigate) { setOpen(false); setExpanded(false); onOpen(item.target); }
      else await refresh();
    } catch (err) { if (mounted.current) setError(err); }
    finally { if (mounted.current) setWorking(false); }
  }
  async function archive(item) {
    if (working || paused) return;
    setWorking(true); setError(null);
    try { await request(`/notifications/${item.id}/archive`, { method: 'POST', body: { archived: !item.archived_at } }); await refresh(); }
    catch (err) { if (mounted.current) setError(err); }
    finally { if (mounted.current) setWorking(false); }
  }
  const bell = <button className={`notification-bell notification-bell-premium ${anchor ? 'notification-bell-inline' : 'notification-bell-fallback'}`} aria-label={`Open notifications, ${unread} unread`} aria-haspopup="dialog" aria-expanded={open} disabled={paused} onClick={() => { setOpen(true); refresh(); }}><Bell size={20} /><span>Notifications</span>{unread > 0 && <b>{unread > 99 ? '99+' : unread}</b>}{error && !open && <i title="Notifications could not refresh" />}</button>;
  return <>{anchor ? createPortal(bell, anchor) : bell}{open && <Modal className={`notification-drawer ${expanded ? 'notification-expanded' : ''}`} icon={Bell} title="Notifications" subtitle={`Your work updates · ${user.name}`} onClose={() => { setOpen(false); setExpanded(false); }}>
    <div className="notification-hub-summary"><span><strong>{unread}</strong> unread updates</span><span><span />Refreshes every 30s</span></div>
    <nav className="notification-tabs" aria-label="Notification views">{[['all', 'All'], ['unread', 'Unread'], ['actions', 'Action required'], ['archived', 'Archived']].map(([key, label]) => <button key={key} className={view === key ? 'is-active' : ''} aria-pressed={view === key} onClick={() => setView(key)}>{label}</button>)}</nav>
    <div className="notification-hub-toolbar"><label><span>Category</span><select aria-label="Notification category" value={category} onChange={event => setCategory(event.target.value)}>{[['all', 'All updates'], ['assignments', 'Assignments'], ['reviews', 'Reviews'], ['decisions', 'Decisions'], ['deadlines', 'Deadlines']].map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label><button className="text-button" disabled={busy || working || paused} onClick={() => refresh()} aria-label="Refresh notifications"><RefreshCw size={16} /></button><button className="text-button" disabled={!unread || working || paused} onClick={() => mark(null)}><CheckCheck size={16} />Mark all read</button></div>
    <div className="notification-hub-body" ref={list} aria-busy={busy}><ErrorBox error={error} />{busy && !items.length ? <div className="empty"><LoaderCircle className="spin" /><p>Loading your updates…</p></div> : !items.length ? <Empty icon={view === 'archived' ? Archive : Inbox} title={view === 'actions' ? 'No actions waiting for you' : view === 'unread' ? 'You’re all caught up' : 'No updates in this view'} text={view === 'actions' ? 'Reading a notification does not complete its work. This view follows current task status.' : 'Assignments, review decisions and deadline reminders will appear here.'} /> : items.map((item, index) => {
      const [title, Icon, tone] = presentation[item.type] || ['Work update', Bell, 'blue'];
      const group = dayGroup(item.created_at), previous = index ? dayGroup(items[index - 1].created_at) : null;
      const action = item.target.workspace === 'results' ? (item.action_required ? item.type === 'kpi_submission' ? 'Review result' : 'Correct result' : 'View result') : item.target.page === 'reviews' && item.action_required ? 'Review submission' : item.target.task_id ? 'Open task' : 'View work';
      return <div key={item.id}>{group !== previous && <h3 className="notification-day-group">{group}</h3>}<article className={`notification-card ${!item.read_at && !item.archived_at ? 'is-unread' : ''}`}><span className={`notification-card-icon is-${tone}`}><Icon size={20} /></span><div className="notification-card-copy"><header><strong>{title}</strong><time dateTime={item.created_at}>{dateLabel(item.created_at)}</time></header><p>{item.message}</p><div className="notification-work-state">{item.action_required ? <span className="notification-action-tag">Action required</span> : <span>{item.work_state === 'approved' || item.work_state === 'Completed' ? 'Work completed' : item.available ? 'Update' : 'No longer available'}</span>}{!item.read_at && !item.archived_at && <span className="notification-unread-tag">Unread</span>}</div><div className="notification-card-actions">{item.available && <button className="text-button notification-open-work" disabled={working || paused} onClick={() => mark(item, true)}>{item.target?.work_change_id ? 'Open work change' : action}<ArrowRight size={14} /></button>}{!item.read_at && <button className="text-button" disabled={working || paused} onClick={() => mark(item)} aria-label={`Mark read: ${item.message}`}><Check size={14} />Read</button>}<button className="text-button" disabled={working || paused} onClick={() => archive(item)} aria-label={`${item.archived_at ? 'Restore' : 'Archive'}: ${item.message}`}>{item.archived_at ? <ArchiveRestore size={14} /> : <Archive size={14} />}{item.archived_at ? 'Restore' : 'Archive'}</button></div></div></article></div>;
    })}{next && <button className="btn btn-secondary notification-load-more" disabled={busy || working || paused} onClick={() => refresh(next)}>{busy ? 'Loading…' : 'Load older updates'}</button>}</div>
    <div className="notification-hub-footer"><span>Read status and work completion are tracked separately.</span><button className="text-button" onClick={() => setExpanded(value => !value)}>{expanded ? 'Compact view' : 'View all notifications'}<ArrowRight size={15} /></button></div>
  </Modal>}</>;
}
