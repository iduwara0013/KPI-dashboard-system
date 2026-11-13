import { useEffect, useId, useRef, useState } from 'react';
import { Network, Minus, Plus, RotateCcw, GripVertical, WandSparkles, ChevronRight, Users, Link2, UserCheck, MousePointer2 } from 'lucide-react';
import { request } from '../../services/api.js';
import { Avatar, Empty, ErrorBox } from '../../components/ui.jsx';
import './organization-chart.css';
import { reportingLayout, reportingConnector, safePosition } from './reportingLayout.js';

const same = (a, b) => String(a) === String(b);
export const reportingRoles = { hof: 'Head of function', manager: 'Manager', employee: 'Employee' };

export default function OrganizationChart() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [department, setDepartment] = useState('');
  const [selected, setSelected] = useState(null);
  async function load() {
    try {
      const result = await request('/organization-chart');
      setData(result);
      setDepartment(previous => previous || String(result.departments[0]?.id || ''));
      setError(null);
    } catch (err) {
      setError(err);
    }
  }
  useEffect(() => { load(); }, []);
  const people = (data?.people || []).filter(person => same(person.department_id, department));
  const links = (data?.links || []).filter(link => same(link.department_id, department));
  const person = people.find(item => same(item.id, selected));
  return <section className="panel org-chart">
    <div className="panel-heading"><div><h2><Network size={19} /> Reporting connections</h2><p>Select a person to view their reporting details.</p></div></div>
    <ErrorBox error={error} />
    {error ? <button className="btn btn-secondary" onClick={load}>Retry</button> : !data ? <p className="org-loading">Loading reporting structure…</p> :
      !people.length ? <Empty icon={Network} title="Your reporting structure will appear here" text="An administrator can connect people using department responsibilities and reporting managers." /> : <>
        <div className="org-toolbar"><label>Department<select aria-label="Chart department" value={department} onChange={event => {
          setDepartment(event.target.value);
          setSelected(null);
        }}>{data.departments.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
          <span className="org-scope-stats"><span><Users size={15} /><strong>{people.length}</strong> people</span><span><Link2 size={15} /><strong>{links.length}</strong> reporting links</span></span>
        </div>
        <ReportingGraph key={department} people={people} links={links} selected={selected} onSelect={setSelected} />
        {person && <div className="org-details"><Avatar name={person.name} /><div>
          <span className="org-profile-label">SELECTED TEAM MEMBER</span><strong>{person.name}</strong><p>{person.designation} · {reportingRoles[person.responsibility]}</p>
          <small><UserCheck size={14} /> Reports to: {links.filter(link => same(link.user_id, person.id)).map(link => people.find(item => same(item.id, link.manager_id))?.name).filter(Boolean).join(', ') || 'No reporting manager configured'}</small>
        </div></div>}
        {!person && <div className="org-selection-hint"><MousePointer2 size={17} /><span>Select a person to view their designation and reporting manager.</span></div>}

      </>}
  </section>;
}

export function ReportingGraph({ people, links, selected, onSelect, showUnassigned = false, editableLayout = false, layoutKey = '' }) {
  const [zoom, setZoom] = useState(1);
  const [collapsed, setCollapsed] = useState(new Set());
  const canvas = useRef(null);
  const [canvasWidth, setCanvasWidth] = useState(900);
  useEffect(() => {
    if (!canvas.current) return;
    const observer = new ResizeObserver(([entry]) => setCanvasWidth(entry.contentRect.width));
    observer.observe(canvas.current);
    return () => observer.disconnect();
  }, []);
  const storageKey = `cpstl:reporting-layout:v1:${layoutKey}`;
  const [manual, setManual] = useState(() => {
    if (!editableLayout || !layoutKey) return {};
    try {
      const stored = JSON.parse(localStorage.getItem(storageKey) || '{}');
      return Object.fromEntries(Object.entries(stored).filter(([, point]) => safePosition(point)));
    } catch { return {}; }
  });
  const [heights, setHeights] = useState({});
  const [layoutMessage, setLayoutMessage] = useState('');
  const [dragging, setDragging] = useState(null);
  const drag = useRef(null), frame = useRef(null);
  const markerId = `reporting-arrow-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
  const { positions, width, height } = reportingLayout(people, links, collapsed, canvasWidth, heights, manual, showUnassigned);
  const visibleIds = Object.keys(positions).sort().join(',');
  useEffect(() => {
    const nodes = [...(canvas.current?.querySelectorAll('[data-person-id]') || [])];
    const observer = new ResizeObserver(entries => {
      setHeights(previous => {
        const next = { ...previous }; let changed = false;
        for (const entry of entries) {
          const id = entry.target.dataset.personId, measured = entry.target.offsetHeight;
          if (measured && next[id] !== measured) { next[id] = measured; changed = true; }
        }
        return changed ? next : previous;
      });
    });
    for (const node of nodes) observer.observe(node);
    return () => observer.disconnect();
  }, [visibleIds]);
  useEffect(() => () => { if (frame.current) cancelAnimationFrame(frame.current); }, []);

  function persist(next) {
    const ids = new Set(people.map(person => String(person.id)));
    try {
      localStorage.setItem(storageKey, JSON.stringify(Object.fromEntries(Object.entries(next).filter(([id]) => ids.has(id)))));
      setLayoutMessage('Layout saved in this browser');
    } catch { setLayoutMessage('Layout changed for this visit; browser storage is unavailable'); }
  }
  function movePoint(event) {
    const current = drag.current;
    if (!current || event.pointerId !== current.pointerId) return;
    event.preventDefault();
    current.next = safePosition({ x: current.start.x + (event.clientX - current.clientX + canvas.current.scrollLeft - current.scrollLeft) / current.zoom,
      y: current.start.y + (event.clientY - current.clientY + canvas.current.scrollTop - current.scrollTop) / current.zoom });
    if (!frame.current) frame.current = requestAnimationFrame(() => {
      frame.current = null;
      if (drag.current) {
        const { id, next } = drag.current;
        setManual(previous => ({ ...previous, [id]: next }));
      }
    });
  }
  function startDrag(event, id) {
    if (event.button !== 0 || drag.current) return;
    event.preventDefault(); event.stopPropagation();
    event.currentTarget.focus();
    event.currentTarget.setPointerCapture(event.pointerId);
    drag.current = { id, pointerId: event.pointerId, start: positions[id], next: positions[id], before: manual[id], clientX: event.clientX, clientY: event.clientY,
      scrollLeft: canvas.current.scrollLeft, scrollTop: canvas.current.scrollTop, zoom };
    setDragging(id);
  }
  function finishDrag(event, cancel = false) {
    const current = drag.current;
    if (!current || current.pointerId !== event.pointerId) return;
    if (!cancel) movePoint(event);
    if (frame.current) { cancelAnimationFrame(frame.current); frame.current = null; }
    const next = { ...manual };
    if (cancel) { if (current.before) next[current.id] = current.before; else delete next[current.id]; }
    else next[current.id] = current.next;
    drag.current = null; setDragging(null); setManual(next);
    if (!cancel) persist(next);
    if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
  }
  function moveWithKeyboard(event, id) {
    const steps = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
    if (!steps[event.key]) return;
    event.preventDefault(); event.stopPropagation();
    const step = event.shiftKey ? 100 : 20, [x, y] = steps[event.key];
    const next = { ...manual, [id]: safePosition({ x: positions[id].x + x * step, y: positions[id].y + y * step }) };
    setManual(next); persist(next);
  }
  function arrange() {
    setManual({}); setCollapsed(new Set());
    try { localStorage.removeItem(storageKey); } catch { /* The chart can still be arranged for this visit. */ }
    setLayoutMessage('Automatic layout restored');
  }
  const selectedX = positions[selected]?.x, selectedY = positions[selected]?.y;
  useEffect(() => {
    if (selected == null || drag.current) return;
    const node = [...(canvas.current?.querySelectorAll('[data-person-id]') || [])].find(node => same(node.dataset.personId, selected));
    node?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }, [selected, selectedX, selectedY]);

  function toggleBranch(id) {
    setCollapsed(previous => {
      const next = new Set(previous);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  return <>
    <div className="org-toolbar org-zoom"><span className="org-map-caption"><Network size={16} />Reporting map</span>
      <button className="icon-button" aria-label="Zoom out chart" onClick={() => setZoom(value => Math.max(.5, value - .1))}><Minus size={16} /></button>
      <small aria-live="polite">{Math.round(zoom * 100)}%</small>
      <button className="icon-button" aria-label="Zoom in chart" onClick={() => setZoom(value => Math.min(1.5, value + .1))}><Plus size={16} /></button>
      <button className="icon-button" aria-label="Reset chart" onClick={() => { setZoom(1); setCollapsed(new Set()); }}><RotateCcw size={16} /></button>
      {editableLayout && <button className="btn btn-secondary btn-small org-auto-arrange" onClick={arrange}><WandSparkles size={15} />Auto arrange</button>}
    </div>
    {editableLayout && <div className="org-layout-hint"><span>Drag the grip to arrange cards · Arrow keys move the focused card</span><span role="status">{layoutMessage || 'Layout only · reporting managers stay unchanged'}</span></div>}
    <div ref={canvas} className="org-canvas" tabIndex={0} aria-label="Reporting chart, scroll to explore">
      <div style={{ width: width * zoom, height: height * zoom }}>
        <div className="org-scene" style={{ width, height, transform: `scale(${zoom})` }}>
          <svg width={width} height={height} aria-hidden="true">
            <defs>{[false, true].map(pending => <marker key={String(pending)} id={`${markerId}${pending ? '-pending' : ''}`} viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto"><polygon points="0,0 8,4 0,8" fill={pending ? 'var(--theme-red, #b33229)' : 'var(--theme-muted, #7891ad)'} /></marker>)}</defs>
            {links.filter(link => positions[link.manager_id] && positions[link.user_id]).map(link => {
              const start = positions[link.manager_id];
              const end = positions[link.user_id];
              return <path key={`${link.manager_id}:${link.user_id}`} className={`${link.pending ? 'org-link-pending' : ''} ${same(link.manager_id, selected) || same(link.user_id, selected) ? 'org-link-selected' : ''}`}
                markerEnd={`url(#${markerId}${link.pending ? '-pending' : ''})`} d={reportingConnector(start, end, heights[link.manager_id], heights[link.user_id])} />;
            })}
          </svg>
          {people.filter(person => positions[person.id]).map(person => {
            const children = links.filter(link => same(link.manager_id, person.id)).length;
            const id = String(person.id);
            return <div data-person-id={id} className={`org-node org-${person.responsibility} ${same(person.id, selected) ? 'org-selected' : ''} ${editableLayout ? 'org-layout-editable' : ''} ${dragging === id ? 'org-dragging' : ''}`} key={id}
              style={{ left: positions[id].x, top: positions[id].y }}>
              {editableLayout && <button type="button" className="org-drag-handle" aria-label={`Move ${person.name}`} title="Drag to arrange. Arrow keys move; Shift + arrow moves further." onPointerDown={event => startDrag(event, id)} onPointerMove={movePoint} onPointerUp={finishDrag} onPointerCancel={event => finishDrag(event, true)} onLostPointerCapture={event => finishDrag(event, true)} onKeyDown={event => moveWithKeyboard(event, id)}><GripVertical size={18} /></button>}
              <button className="org-person" title={`${person.name} — ${person.responsibility_title || person.designation || reportingRoles[person.responsibility]}`} aria-pressed={same(person.id, selected)} onClick={() => onSelect(person.id)}>
                <Avatar name={person.name} small /><span>
                  <small>{reportingRoles[person.responsibility]}{person.is_active === false && ' · Inactive'}{showUnassigned && person.responsibility !== 'hof' && !links.some(link => same(link.user_id, id)) && ' · Unassigned'}</small>
                  <strong>{person.name}</strong><em>{person.responsibility_title || person.designation || 'Designation not set'}</em>
                </span>
              </button>
              {children > 0 && <button className="org-branch" aria-expanded={!collapsed.has(id)} onClick={() => toggleBranch(id)}>{children} direct reports <ChevronRight size={12} /></button>}
            </div>;
          })}
        </div>
      </div>
    </div>
  </>;
}
