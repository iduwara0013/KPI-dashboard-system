import Timeliness from '../../components/Timeliness.jsx';
import { useEffect, useRef, useState } from 'react';
import { AlertCircle, ArrowRight, CalendarDays, CheckCircle2, ClipboardList, Clock3, Maximize, Minimize, Monitor, RefreshCw, Settings2, ShieldCheck, Target, UserCheck, Users }  from 'lucide-react';
import { request } from '../../services/api.js';
import './tv-display.css';
import { deliveryStats } from '../md/mdMetrics.js';

const pct = n => `${Number(n.toFixed(1))}%`;
const completion = rows => deliveryStats(rows).completion;
const formatTime = date => date.toLocaleTimeString('en-GB', { timeZone: 'Asia/Colombo' });
const dateLabel = value => new Date(value.slice(0, 10) + 'T00:00:00Z').toLocaleDateString('en-GB', { timeZone: 'UTC', day: 'numeric', month: 'short' });
const initials = name => name.split(' ').filter(Boolean).slice(0, 2).map(word => word[0]).join('').toUpperCase();
function CompletionGauge({ value }) {
  const progress = Math.max(0, Math.min(100, value || 0));
  return <div className="tv-completion-gauge" role="img" aria-label={value === null ? 'No weighted work in this scope' : `${pct(value)} weighted work completion`} style={{ '--completion': `${progress}%` }}><div><strong>{value === null ? '—' : Number(value.toFixed(1))}<small>{value === null ? '' : '%'}</small></strong><span>APPROVED WORK</span></div></div>;
}
export default function TvDisplay({ paused }) {
  const params = new URLSearchParams(window.location.search);
  const [year, setYear] = useState(params.get('year') || String(new Date().getFullYear())), [department, setDepartment] = useState(params.get('department') || 'all');
  const [snapshot, setSnapshot] = useState(null);
  const [error, setError] = useState(null);
  const [now, setNow] = useState(new Date());
  const [rotate, setRotate] = useState(params.get('rotate') === '1');
  const [cleanMode, setCleanMode] = useState(false);
  const [controlsVisible, setControlsVisible] = useState(true);
  const [notice, setNotice] = useState('');
  const [refreshToken, setRefreshToken] = useState(0);
  const [secondsLeft, setSecondsLeft] = useState(30);
  const [teamPage, setTeamPage] = useState(0);
  const [screenHeight, setScreenHeight] = useState(window.innerHeight);
  const displayRef = useRef(null);
  const controlsTimer = useRef(null);
  const data = snapshot?.year === Number(year) ? snapshot : null;

  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 1000);
    controlsTimer.current = setTimeout(() => setControlsVisible(false), 6000);
    const handleFullscreenChange = () => {
      setCleanMode(Boolean(document.fullscreenElement));
      setControlsVisible(false);
      setNotice('');
    };
    const resize = () => setScreenHeight(window.innerHeight);
    window.addEventListener('resize', resize);
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    return () => {
      clearInterval(timer);
      clearTimeout(controlsTimer.current);
      document.removeEventListener('fullscreenchange', handleFullscreenChange);
      window.removeEventListener('resize', resize);
    };
  }, []);

  useEffect(() => {
    if (paused) return;
    let cancelled = false;
    let activeRequest;
    const load = async () => {
      if (activeRequest || document.visibilityState !== 'visible') return;
      activeRequest = new AbortController();
      const timeout = setTimeout(() => activeRequest.abort(), 15000);
      try {
        const result = await request(`/display/workspace?year=${encodeURIComponent(year)}`, { signal: activeRequest.signal });
        if (cancelled) return;
        setSnapshot(result);
        setError(null);
        setDepartment(previous => {
          if (previous === 'all' || result.departments.some(item => String(item.id) === previous)) return previous;
          return String(result.departments[0]?.id || 'all');
        });
      } catch (err) {
        if (cancelled) return;
        const offline = err.status === 0 || err.status >= 500 || err.status === 408 || err.status === 429;
        setError({ message: err.message, offline });
        if (!offline) setSnapshot(null);
      } finally {
        clearTimeout(timeout);
        activeRequest = null;
      }
    };
    load();
    const timer = setInterval(load, 30000);
    window.addEventListener('focus', load);
    window.addEventListener('online', load);
    window.addEventListener('offline', load);
    document.addEventListener('visibilitychange', load);
    return () => {
      cancelled = true;
      activeRequest?.abort();
      clearInterval(timer);
      window.removeEventListener('focus', load);
      window.removeEventListener('online', load);
      window.removeEventListener('offline', load);
      document.removeEventListener('visibilitychange', load);
    };
  }, [year, paused, refreshToken]);
  useEffect(() => { window.history.replaceState(null, '', `/display?year=${encodeURIComponent(year)}&department=${encodeURIComponent(department)}&rotate=${rotate ? 1 : 0}`); }, [year, department, rotate]);
  const departments = data?.departments || [];
  const departmentIndex = departments.findIndex(item => String(item.id) === department);
  const nextDepartment = departments[(departmentIndex + 1) % departments.length];
  const nextDepartmentId = nextDepartment && String(nextDepartment.id);
  const rotationActive = rotate && !paused && !error && departments.length > 1;
  useEffect(() => {
    setSecondsLeft(30);
    if (!rotationActive) return;
    const deadline = performance.now() + 30000;
    const countdown = setInterval(() => setSecondsLeft(Math.max(0, Math.ceil((deadline - performance.now()) / 1000))), 200);
    const advance = setTimeout(() => setDepartment(nextDepartmentId), 30000);
    return () => { clearInterval(countdown); clearTimeout(advance); };
  }, [rotationActive, nextDepartmentId, department, year]);

  function revealControls() {
    setControlsVisible(true);
    clearTimeout(controlsTimer.current);
    controlsTimer.current = setTimeout(() => setControlsVisible(false), 4500);
  }

  async function fullscreen() {
    clearTimeout(controlsTimer.current);
    setControlsVisible(false);
    setNotice('');
    if (cleanMode) {
      try {
        if (document.fullscreenElement) await document.exitFullscreen();
        setCleanMode(false);
      } catch {
        setNotice('Press Esc to exit fullscreen.');
      }
      return;
    }
    setCleanMode(true);
    displayRef.current?.focus({ preventScroll: true });
    try {
      await document.documentElement.requestFullscreen();
    } catch {
      setNotice('Press F11 to fill the screen. Move the pointer or press Tab for controls.');
    }
  }
  const rows = (data?.activities || []).filter(r => department === 'all' || String(r.department_id) === department);
  const approved = completion(rows), complete = rows.filter(r => r.approved === 100).length, pending = rows.reduce((sum, r) => sum + r.pending, 0), overdue = rows.filter(r => r.overdue).length;
  const unassigned = rows.filter(row => !row.assigned).length;
  const reviewNeeded = rows.filter(row => row.needs_review).length;
  const groupedKpis = Object.values(rows.reduce((groups, row) => {
    const key = `${row.key.split(':')[0]}:${row.department_id}:${row.kpa}:${row.kpi}`;
    if (!groups[key]) groups[key] = { key, label: row.kpi, department: departments.find(item => String(item.id) === String(row.department_id))?.name || 'Department', rows: [] };
    groups[key].rows.push(row);
    return groups;
  }, {})).map(group => ({ ...group, progress: completion(group.rows) })).sort((a, b) => (a.progress || 0) - (b.progress || 0)).slice(0, screenHeight < 900 ? 3 : 5);
  const deadlines = rows.filter(r => r.due_date && r.approved < 100).sort((a, b) => a.due_date.localeCompare(b.due_date)).slice(0, 4);
  const team = (data?.team || []).filter(person => department === 'all' || String(person.department_id) === department);
  const employees = Object.values(team.filter(p => p.responsibility === 'employee').reduce((map, person) => {
    if (!map[person.id]) map[person.id] = { ...person, assigned_tasks: 0, approved_tasks: 0, pending_tasks: 0, overdue_tasks: 0 };
    for (const field of ['assigned_tasks', 'approved_tasks', 'pending_tasks', 'overdue_tasks']) map[person.id][field] += person[field];
    return map;
  }, {})).sort((a, b) => a.name.localeCompare(b.name));
  const leaders = role => [...new Map(team.filter(p => p.responsibility === role).map(p => [p.id, p])).values()];
  const teamPageSize = screenHeight < 900 ? 4 : 6;
  const teamPages = Math.max(1, Math.ceil(employees.length / teamPageSize));
  useEffect(() => { setTeamPage(0); }, [department, year]);
  useEffect(() => { if (paused || teamPages < 2) return; const timer = setInterval(() => setTeamPage(page => (page + 1) % teamPages), 10000); return () => clearInterval(timer); }, [teamPages, paused, department, year]);
  const visibleEmployees = employees.slice((teamPage % teamPages) * teamPageSize, (teamPage % teamPages) * teamPageSize + teamPageSize);
  const title = departments.find(item => String(item.id) === department)?.name || 'All permitted departments';
  const status = paused ? 'Paused' : error?.offline ? 'Offline' : error ? 'Unavailable' : data ? 'Live' : 'Connecting';
  const lastUpdated = data && formatTime(new Date(data.as_of));

  const attentionItems = [
    ...(overdue ? [{ key: 'overdue', tone: 'red', title: `${overdue} overdue activit${overdue === 1 ? 'y' : 'ies'}`, detail: 'Delivery deadline has passed', Icon: AlertCircle }] : []),
    ...(unassigned ? [{ key: 'unassigned', tone: 'amber', title: `${unassigned} unassigned activit${unassigned === 1 ? 'y' : 'ies'}`, detail: 'An owner needs to be assigned', Icon: UserCheck }] : []),
    ...(reviewNeeded ? [{ key: 'review', tone: 'amber', title: `${reviewNeeded} assignment${reviewNeeded === 1 ? '' : 's'} to review`, detail: 'Plan or reporting scope changed', Icon: ShieldCheck }] : []),
    ...deadlines.filter(row => !row.overdue).slice(0, 1).map(row => ({ key: row.key, tone: 'blue', title: row.activity, detail: `Next deadline · ${dateLabel(row.due_date)}`, Icon: CalendarDays })),
  ].slice(0, 4);
  return <main ref={displayRef} tabIndex={-1} className={`tv-display${cleanMode ? ' tv-clean' : ''}`} onPointerMove={revealControls}>
    <header className="tv-header">
      <div className="tv-brand"><img src="/cpstl-logo.png" alt="CPSTL" /><div><strong>CPSTL</strong><span>PERFORMANCE MONITOR</span></div></div>
      <div className="tv-title"><span>DEPARTMENT DELIVERY · {year}</span><h1 key={`${year}-${department}`} className="tv-department-title">{title}</h1></div>
      <time>{formatTime(now)}<small>{now.toLocaleDateString('en-GB', { timeZone: 'Asia/Colombo', day: '2-digit', month: 'short', year: 'numeric' })} · Sri Lanka</small></time>
    </header>
    <div className="tv-rotation-track" role="progressbar" aria-label="Time until next department" aria-valuemin={0} aria-valuemax={30} aria-valuenow={rotationActive ? secondsLeft : 30} aria-valuetext={rotationActive ? `${secondsLeft} seconds until ${nextDepartment.name}` : 'Department rotation paused'}><i key={`${year}-${department}-${rotationActive}-${nextDepartmentId}`} className={rotationActive ? 'tv-countdown-running' : ''} /></div>
    <div className="tv-display-info"><div className={`tv-feed-status tv-feed-${status.toLowerCase()}`} role="status"><strong>{status}</strong><span>{error?.offline && data ? 'Connection lost · Showing last received data · ' : ''}{lastUpdated ? `Last updated ${lastUpdated}` : 'Waiting for department data'}</span></div><div className="tv-info-actions">{rotationActive && <div className="tv-next-department"><span>Up next</span><strong>{nextDepartment.name}</strong><b>{secondsLeft}s</b></div>}<button className="tv-settings-toggle" aria-expanded={controlsVisible} aria-controls="tv-display-controls" onClick={() => { clearTimeout(controlsTimer.current); setControlsVisible(value => !value); }}><Settings2 size={15} /><span>Display controls</span></button></div></div>
    <div className={`tv-controls-dock ${controlsVisible ? 'is-visible' : ''}`}><div id="tv-display-controls" className="tv-controls" aria-label="TV display controls" role="group"><Monitor size={17} /><select aria-label="TV performance year" value={year} onChange={event => { setError(null); setYear(event.target.value); }}>{[...new Set([year, ...(snapshot?.years || []).map(String)])].sort().reverse().map(value => <option key={value}>{value}</option>)}</select><select aria-label="TV department" value={department} onChange={event => setDepartment(event.target.value)}><option value="all">All permitted departments</option>{departments.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select><label><input type="checkbox" checked={rotate} onChange={event => setRotate(event.target.checked)} />Rotate · 30s</label><button onClick={() => setRefreshToken(token => token + 1)}><RefreshCw size={15} />Refresh</button><button onClick={fullscreen}>{cleanMode ? <Minimize size={15} /> : <Maximize size={15} />}{cleanMode ? 'Exit fullscreen' : 'Fullscreen'}</button><a href="/">Back to workspace<ArrowRight size={14} /></a></div></div>
    {notice && <p role="status" className="tv-notice">{notice}</p>}
    {paused ? <div className="tv-empty"><ShieldCheck size={38} /><h2>Display paused</h2><p>Sign in again to continue viewing department data.</p></div> : error && !data ? <div className="tv-empty" role="alert"><AlertCircle size={38} /><h2>Display unavailable</h2><p>{error.message}</p><p>Use display controls to reconnect or return to your workspace.</p></div> : !data ? <div className="tv-empty"><Monitor size={38} /><h2>Connecting to department data…</h2></div> : <div key={`${year}-${department}`} className="tv-scene">
      <section className="tv-leadership" aria-label="Department leadership">{[['hof', 'Head of function', ShieldCheck], ['manager', 'Managers', Users]].map(([role, label, Icon]) => <div className="tv-leader" key={role}><span className={`tv-leader-icon tv-leader-${role}`}><Icon size={20} /></span><div><small>{label}</small><strong title={leaders(role).map(person => `${person.name} · ${person.designation}`).join(', ')}>{leaders(role).length ? leaders(role).slice(0, 3).map(person => `${person.name} · ${person.designation}`).join(' / ') + (leaders(role).length > 3 ? ` +${leaders(role).length - 3} more` : '') : 'Not configured'}</strong></div></div>)}<div className="tv-staff-total"><Users size={18} /><strong>{employees.length}</strong><span>Employees in scope</span></div></section>
      <section className="tv-metrics" aria-label="Delivery summary">{[['Activities', rows.length, ClipboardList, 'blue'], ['Fully approved', complete, CheckCircle2, 'green'], ['Pending reviews', pending, Clock3, pending ? 'amber' : 'neutral'], ['Overdue', overdue, AlertCircle, overdue ? 'red' : 'neutral'], ['Unassigned', unassigned, UserCheck, unassigned ? 'amber' : 'neutral']].map(([label, value, Icon, tone]) => <article className={`tv-metric tv-tone-${tone}`} key={label}><span className="tv-metric-icon"><Icon size={20} /></span><div><span>{label}</span><strong>{value}</strong></div></article>)}</section>
      <div className="tv-board">
        <section className="tv-card tv-performance-card"><header><span className="tv-section-label"><Target size={15} />WEIGHTED DELIVERY</span><h2>Approved work completion</h2></header><div className="tv-performance-body"><CompletionGauge value={approved} /><div><span className="tv-performance-badge"><CheckCircle2 size={14} />{complete} of {rows.length} activities approved</span><Timeliness value={deliveryStats(rows).timeliness} compact /><p>Agreed weights determine each activity’s contribution.</p><small>Work completion · KPI outcome scores are evaluated separately</small></div></div></section>
        <section className="tv-card tv-kpi-card"><header><div><span className="tv-section-label">KPI DELIVERY</span><h2>Where work stands</h2></div><span className="tv-card-hint">Lowest completion first</span></header><div className="tv-bars">{groupedKpis.map(group => <div key={group.key}><div className="tv-bar-label"><span title={group.label}>{group.label}</span><b>{group.progress === null ? '—' : pct(group.progress)}</b></div>{department === 'all' && <small>{group.department}</small>}<div className="tv-bar-track"><i style={{ width: `${group.progress || 0}%` }} className={group.progress === 100 ? 'is-complete' : ''} /></div></div>)}</div>{!rows.length && <p className="tv-no-work">No published work in this view.</p>}</section>
        <section className={`tv-card tv-team-card ${visibleEmployees.length <= 2 ? 'tv-team-compact' : ''}`}><header><div><span className="tv-section-label"><Users size={15} />TEAM DELIVERY</span><h2>People behind the progress</h2></div><span className="tv-page-indicator">{teamPage % teamPages + 1} / {teamPages}</span></header><div className="tv-employee-grid" key={`${department}-${teamPage}`}>{visibleEmployees.map(person => {
          const progress = person.assigned_tasks ? person.approved_tasks / person.assigned_tasks * 100 : null;
          return <article className={`tv-employee ${person.overdue_tasks ? 'has-overdue' : ''}`} key={person.id}><div className="tv-employee-heading"><span className="tv-employee-avatar">{initials(person.name)}</span><div><h3 title={person.name}>{person.name}</h3><p title={person.designation}>{person.designation || 'Designation not set'}</p></div><span className="tv-employee-score">{progress === null ? '—' : pct(progress)}<small>task completion</small></span></div><div className="tv-person-progress"><i style={{ width: `${progress || 0}%` }} /></div><div className="tv-employee-counts"><span><strong>{person.assigned_tasks}</strong>Assigned</span><span className="is-approved"><strong>{person.approved_tasks}</strong>Approved</span><span className={person.pending_tasks ? 'is-pending' : ''}><strong>{person.pending_tasks}</strong>In review</span><span className={person.overdue_tasks ? 'is-overdue' : ''}><strong>{person.overdue_tasks}</strong>Overdue</span></div></article>;
        })}</div>{!employees.length && <div className="tv-team-empty"><Users size={32} /><strong>No employees in this scope</strong><span>Configured reporting people appear here.</span></div>}<div className="tv-team-caption"><span><span className="tv-caption-dot" />Task completion = approved tasks ÷ assigned tasks</span><span>{teamPages > 1 ? 'Cards rotate every 10 seconds' : `${employees.length} employee${employees.length === 1 ? '' : 's'} in scope`}</span></div></section>
      </div>
      <section className={`tv-attention-strip ${attentionItems.length ? '' : 'is-clear'}`} aria-label="Delivery attention"><span className="tv-attention-heading">{attentionItems.length ? <AlertCircle size={20} /> : <CheckCircle2 size={20} />}<span>{attentionItems.length ? 'ATTENTION' : 'ALL CLEAR'}<small>{attentionItems.length ? 'Next delivery priorities' : 'No ownership or deadline alerts'}</small></span></span><div>{attentionItems.map(({ key, tone, title, detail, Icon }) => <article key={key} className={`tv-alert-${tone}`}><Icon size={17} /><div><strong title={title}>{title}</strong><small>{detail}</small></div></article>)}{!attentionItems.length && <p>{rows.length ? 'No open ownership or deadline alerts in this view.' : 'Published activities will appear when available.'}</p>}</div></section>
    </div>}
    <footer className="tv-footer"><span><ShieldCheck size={12} />{data?.scope || 'Authenticated display'} · Read only</span><span>Weighted work completion and employee task counts are separate measures.</span><span><RefreshCw size={12} />Auto-refresh · 30s</span></footer>
  </main>;
}
