import { useEffect, useRef, useState } from 'react';
import { Maximize, Minimize, Monitor, RefreshCw } from 'lucide-react';
import { request } from './api.js';
import './tv-display.css';
import { deliveryStats } from './mdMetrics.js';

const pct = n => `${Number(n.toFixed(1))}%`;
const colors = ['#4d9c80', '#d7a044', '#7094bd', '#a18bbb', '#bd675c'];
const completion = rows => deliveryStats(rows).completion;
const formatTime = date => date.toLocaleTimeString('en-GB', { timeZone: 'Asia/Colombo' });
function Ring({ values, center, caption }) {
  const total = values.reduce((s, v) => s + v.value, 0); let start = 0;
  return <div className="tv-ring"><svg viewBox="0 0 180 180" role="img" aria-label={`${caption}: ${center}. ${values.map(v => `${v.label}: ${v.value}`).join(', ')}`}><circle cx="90" cy="90" r="64" stroke="#ecf0f6" strokeWidth="22" fill="none" />{values.filter(v => v.value > 0).map((v, i) => { const amount = v.value / total * 100, offset = start; start += amount; return <circle key={v.label} cx="90" cy="90" r="64" fill="none" stroke={v.color || colors[i % colors.length]} strokeWidth="22" pathLength="100" strokeDasharray={`${amount} ${100 - amount}`} strokeDashoffset={-offset} transform="rotate(-90 90 90)" />; })}<text x="90" y="91" textAnchor="middle">{center}</text><text className="tv-ring-caption" x="90" y="111" textAnchor="middle">{caption}</text></svg><div className="tv-ring-key">{values.map((v, i) => <span key={v.label}><i style={{ background: v.color || colors[i % colors.length] }} />{v.label}<b>{Number(v.value.toFixed(1))}</b></span>)}</div></div>;
}
export default function TvDisplay({ paused }) {
  const params = new URLSearchParams(window.location.search);
  const [year, setYear] = useState(params.get('year') || String(new Date().getFullYear())), [department, setDepartment] = useState(params.get('department') || 'all');
  const [snapshot, setSnapshot] = useState(null);
  const [error, setError] = useState(null);
  const [now, setNow] = useState(new Date());
  const [rotate, setRotate] = useState(false);
  const [cleanMode, setCleanMode] = useState(false);
  const [controlsVisible, setControlsVisible] = useState(false);
  const [notice, setNotice] = useState('');
  const [refreshToken, setRefreshToken] = useState(0);
  const [secondsLeft, setSecondsLeft] = useState(30);
  const [teamPage, setTeamPage] = useState(0);
  const displayRef = useRef(null);
  const controlsTimer = useRef(null);
  const data = snapshot?.year === Number(year) ? snapshot : null;

  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 1000);
    const handleFullscreenChange = () => {
      setCleanMode(Boolean(document.fullscreenElement));
      setControlsVisible(false);
      setNotice('');
    };
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    return () => {
      clearInterval(timer);
      clearTimeout(controlsTimer.current);
      document.removeEventListener('fullscreenchange', handleFullscreenChange);
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
  useEffect(() => { window.history.replaceState(null, '', `/display?year=${encodeURIComponent(year)}&department=${encodeURIComponent(department)}`); }, [year, department]);
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
    if (!cleanMode) return;
    setControlsVisible(true);
    clearTimeout(controlsTimer.current);
    controlsTimer.current = setTimeout(() => setControlsVisible(false), 2500);
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
  const statuses = [{ label: 'Approved', value: complete, color: '#4d9c80' }, { label: 'Review needed', value: rows.filter(r => r.needs_review).length, color: '#bd675c' }, { label: 'Unassigned', value: rows.filter(r => !r.assigned).length, color: '#a18bbb' }, { label: 'In delivery', value: rows.filter(r => r.approved < 100 && !r.needs_review && r.assigned).length, color: '#7094bd' }];
  const group = field => [...new Set(rows.map(r => r[field]))].map(label => ({ label, rows: rows.filter(r => r[field] === label) })).map(g => ({ ...g, progress: completion(g.rows) || 0 })).sort((a, b) => a.progress - b.progress).slice(0, 5);
  const deadlines = rows.filter(r => r.due_date && r.approved < 100).sort((a, b) => a.due_date.localeCompare(b.due_date)).slice(0, 4);
  const team = (data?.team || []).filter(person => department === 'all' || String(person.department_id) === department);
  const employees = Object.values(team.filter(p => p.responsibility === 'employee').reduce((map, person) => {
    if (!map[person.id]) map[person.id] = { ...person, assigned_tasks: 0, approved_tasks: 0, pending_tasks: 0, overdue_tasks: 0 };
    for (const field of ['assigned_tasks', 'approved_tasks', 'pending_tasks', 'overdue_tasks']) map[person.id][field] += person[field];
    return map;
  }, {})).sort((a, b) => a.name.localeCompare(b.name));
  const leaders = role => [...new Map(team.filter(p => p.responsibility === role).map(p => [p.id, p])).values()];
  const teamPages = Math.max(1, Math.ceil(employees.length / 6));
  useEffect(() => { setTeamPage(0); }, [department, year]);
  useEffect(() => { if (paused || teamPages < 2) return; const timer = setInterval(() => setTeamPage(page => (page + 1) % teamPages), 10000); return () => clearInterval(timer); }, [teamPages, paused, department, year]);
  const visibleEmployees = employees.slice((teamPage % teamPages) * 6, (teamPage % teamPages) * 6 + 6);
  const title = departments.find(item => String(item.id) === department)?.name || 'All permitted departments';
  const status = paused ? 'Paused' : error?.offline ? 'Offline' : error ? 'Unavailable' : data ? 'Live' : 'Connecting';
  const lastUpdated = data && formatTime(new Date(data.as_of));

  return <main
    ref={displayRef}
    tabIndex={-1}
    className={`tv-display${cleanMode ? ' tv-clean' : ''}`}
    onPointerMove={revealControls}
    onPointerDown={revealControls}
  >
    <header className="tv-header">
      <div className="tv-brand"><img src="/cpstl-logo.png" alt="CPSTL" /><div><strong>CPSTL</strong><span>PERFORMANCE MONITOR</span></div></div>
      <div className="tv-title">
        <span>DEPARTMENT DELIVERY · {year}</span>
        <h1 key={`${year}-${department}`} className="tv-department-title">{title}</h1>
      </div>
      <time>{formatTime(now)}<small>{now.toLocaleDateString('en-GB', { timeZone: 'Asia/Colombo', day: '2-digit', month: 'short', year: 'numeric' })} · Sri Lanka</small></time>
    </header>
    <div className="tv-rotation-track" role="progressbar" aria-label="Time until next department" aria-valuemin={0} aria-valuemax={30} aria-valuenow={rotationActive ? secondsLeft : 30} aria-valuetext={rotationActive ? `${secondsLeft} seconds until ${nextDepartment.name}` : 'Department rotation paused'}>
      <i key={`${year}-${department}-${rotationActive}-${nextDepartmentId}`} className={rotationActive ? 'tv-countdown-running' : ''} />
    </div>
    <div className="tv-display-info">
      <div className={`tv-feed-status tv-feed-${status.toLowerCase()}`} role="status">
        <strong>{status}</strong>
        {lastUpdated && !paused && <span>Last updated {lastUpdated}</span>}
      </div>
      {rotationActive && <div className="tv-next-department"><span>Next department</span><strong>{nextDepartment.name}</strong><span>{secondsLeft}s</span></div>}
    </div>
    <div className="tv-controls-dock">
      <div className={`tv-controls${controlsVisible ? ' tv-controls-visible' : ''}`} aria-label="TV display controls" role="group">
        <Monitor size={16} />
        <select aria-label="TV performance year" value={year} onChange={event => { setError(null); setYear(event.target.value); }}>
          {[...new Set([year, ...(snapshot?.years || []).map(String)])].sort().reverse().map(value => <option key={value}>{value}</option>)}
        </select>
        <select aria-label="TV department" value={department} onChange={event => setDepartment(event.target.value)}>
          <option value="all">All permitted departments</option>
          {departments.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
        </select>
        <label><input type="checkbox" checked={rotate} onChange={event => setRotate(event.target.checked)} />Rotate departments · 30s</label>
        <button onClick={() => setRefreshToken(token => token + 1)}><RefreshCw size={14} />Refresh</button>
        <button onClick={fullscreen}>{cleanMode ? <Minimize size={14} /> : <Maximize size={14} />}{cleanMode ? 'Exit fullscreen' : 'Fullscreen'}</button>
        <a href="/">Back to workspace</a>
      </div>
    </div>
    {notice && <p role="status" className="tv-notice">{notice}</p>}{paused ? <div className="tv-empty"><h2>Display paused</h2><p>Sign in again to continue viewing department data.</p></div> : error && !data ? <div className="tv-empty" role="alert"><h2>Display unavailable</h2><p>{error.message}</p><p>Refresh to reconnect, or return to your workspace.</p></div> : !data ? <div className="tv-empty"><h2>Connecting to department data…</h2></div> : <div key={`${year}-${department}`} className="tv-scene"><div className="tv-leadership">{[['hof', 'Head of function'], ['manager', 'Managers']].map(([role, label]) => <div key={role}><span>{label}</span><strong title={leaders(role).map(p => `${p.name} · ${p.designation}`).join(', ')}>{leaders(role).length ? leaders(role).slice(0, 3).map(p => `${p.name} · ${p.designation}`).join(' / ') + (leaders(role).length > 3 ? ` +${leaders(role).length - 3} more` : '') : 'Not configured'}</strong></div>)}<div className="tv-staff-total"><strong>{employees.length}</strong><span>Employees in scope</span></div></div><section className="tv-metrics">{[['Activities', rows.length], ['Fully approved', complete], ['Pending reviews', pending], ['Overdue', overdue], ['Unassigned', rows.filter(r => !r.assigned).length], ['Approved completion', approved === null ? '—' : pct(approved)]].map(([label, value]) => <article key={label}><span>{label}</span><strong>{value}</strong></article>)}</section><div className="tv-board"><section className="tv-card"><h2>Activity status</h2><p>Current published activities</p><Ring values={statuses} center={rows.length} caption="ACTIVITIES" /></section><section className="tv-card"><h2>KPI delivery</h2><p>Lowest completion first · up to 5 KPIs</p><div className="tv-bars">{group('kpi').map(g => <div key={g.label}><span title={g.label}>{g.label}<b>{pct(g.progress)}</b></span><div><i style={{ width: `${g.progress}%` }} /></div></div>)}</div>{!rows.length && <p className="tv-no-work">No published work in this view.</p>}</section><section className="tv-card tv-team-card"><div className="tv-team-heading"><div><h2>Employee delivery</h2><p>Current tasks in this department view · approved task counts</p></div><span>{employees.length} employees · {teamPage % teamPages + 1}/{teamPages}</span></div><table className="tv-team-table"><thead><tr><th>Employee / designation</th><th>Assigned</th><th>Approved</th><th>Awaiting review</th><th>Overdue</th></tr></thead><tbody key={`${department}-${teamPage}`}>{visibleEmployees.map(person => <tr key={person.id}><td><strong>{person.name}</strong><small>{person.designation}</small><div className="tv-person-progress"><i style={{ width: `${person.assigned_tasks ? person.approved_tasks / person.assigned_tasks * 100 : 0}%` }} /></div></td><td>{person.assigned_tasks}</td><td className="tv-approved-count">{person.approved_tasks}</td><td>{person.pending_tasks}</td><td className={person.overdue_tasks ? 'tv-overdue-count' : ''}>{person.overdue_tasks}</td></tr>)}</tbody></table>{!employees.length && <p className="tv-no-work">No employees in this permitted reporting scope.</p>}<div className="tv-team-caption">Bars show approved tasks ÷ assigned tasks. This is a task-count view, separate from weighted KPI scoring.{teamPages > 1 && ' Rows change every 10 seconds.'}</div></section></div></div>}
    <footer className="tv-footer"><span>{data?.scope || 'Authenticated display'} · Read only · Approved work completion</span><span>Auto-refresh every 30s</span></footer>
  </main>;
}


