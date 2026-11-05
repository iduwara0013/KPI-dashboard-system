import { useEffect, useRef, useState } from 'react';
import { ArrowRight, Bell, Check, CheckCheck, LoaderCircle, RefreshCw } from 'lucide-react';
import { request } from './api.js';
import { Empty, ErrorBox, Modal } from './components.jsx';
import './notifications.css';

const dateLabel = date => new Date(date.includes('Z') || date.includes('+') ? date : date.replace(' ', 'T') + 'Z').toLocaleString();
export default function NotificationCenter({ user, paused, onOpen }) {
  const [open, setOpen] = useState(false), [items, setItems] = useState([]), [unread, setUnread] = useState(0), [next, setNext] = useState(null), [error, setError] = useState(null), [busy, setBusy] = useState(false), [working, setWorking] = useState(false);
  const mounted = useRef(true), pending = useRef(false);
  async function refresh(before) {
    if (pending.current) return;
    pending.current = true; setBusy(true);
    try {
      const result = await request(`/notifications${before ? `?before=${before}` : ''}`);
      if (!mounted.current) return;
      setItems(previous => before ? [...previous, ...result.items.filter(item => !previous.some(old => old.id === item.id))] : result.items);
      setUnread(result.unread); setNext(result.next); setError(null);
    } catch (err) { if (mounted.current) setError(err); }
    finally { pending.current = false; if (mounted.current) setBusy(false); }
  }
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);
  useEffect(() => {
    if (paused) return;
    const poll = () => { if (document.visibilityState === 'visible' && !open) refresh(); };
    poll(); const timer = setInterval(poll, 30000);
    window.addEventListener('focus', poll); document.addEventListener('visibilitychange', poll);
    return () => { clearInterval(timer); window.removeEventListener('focus', poll); document.removeEventListener('visibilitychange', poll); };
  }, [paused, open]);
  async function mark(item, navigate = false) {
    if (working) return;
    setWorking(true); setError(null);
    try {
      const result = await request(item ? `/notifications/${item.id}/read` : '/notifications/read-all', { method: 'POST' });
      if (!mounted.current) return;
      setUnread(result.unread); setItems(previous => previous.map(row => !item || row.id === item.id ? { ...row, read_at: row.read_at || new Date().toISOString() } : row));
      if (navigate) { setOpen(false); onOpen(item.target); }
    } catch (err) { if (mounted.current) setError(err); }
    finally { if (mounted.current) setWorking(false); }
  }
  return <><button className="notification-bell" aria-label={`Open notifications${unread ? `, ${unread} unread` : ''}`} aria-haspopup="dialog" onClick={() => { setOpen(true); refresh(); }}><Bell size={21} /><span>Notifications</span>{unread > 0 && <b>{unread > 99 ? '99+' : unread}</b>}{error && !open && <i title="Notifications could not refresh" />}</button>{open && <Modal title="Notifications" subtitle={`Your updates · ${user.name}`} onClose={() => setOpen(false)}><div className="notification-toolbar"><span aria-live="polite">{unread} unread</span><button className="text-button" disabled={busy || working} onClick={() => refresh()}><RefreshCw size={14} />Refresh</button><button className="text-button" disabled={!unread || working} onClick={() => mark(null)}><CheckCheck size={15} />Mark all read</button></div><div className="notification-body"><ErrorBox error={error} />{busy && !items.length ? <div className="empty"><LoaderCircle className="spin" /><p>Loading notifications…</p></div> : !items.length ? <Empty icon={Bell} title="You’re all caught up" text="New assignments, submitted work and review decisions will appear here. Overdue reminders are checked when you open the app." /> : items.map(item => <article className={`notification-item ${!item.read_at ? 'notification-unread' : ''}`} key={item.id}><div className={`notification-symbol notification-${item.type}`}><Bell size={17} /></div><div><small>{item.type.replaceAll('_', ' ')} · {dateLabel(item.created_at)}</small><p>{item.message}</p><div className="notification-actions"><button className="text-button" disabled={working} onClick={() => mark(item, true)}>Open work <ArrowRight size={13} /></button>{!item.read_at && <button className="text-button" disabled={working} onClick={() => mark(item)}><Check size={13} />Mark read</button>}</div></div>{!item.read_at && <span className="notification-dot" aria-label="Unread" />}</article>)}{next && <button className="btn btn-secondary notification-more" disabled={busy || working} onClick={() => refresh(next)}>{busy ? 'Loading…' : 'Load older notifications'}</button>}</div><div className="notification-footer">Updates refresh every 30 seconds while this page is visible. Older updates may refer to work that has since changed.</div></Modal>}</>;
}
