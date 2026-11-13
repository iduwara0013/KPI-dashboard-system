import { useEffect, useState } from 'react';
import { Network, Minus, Plus, RotateCcw, ChevronRight, Users, Link2, UserCheck, MousePointer2 } from 'lucide-react';
import { request } from '../../services/api.js';
import { Avatar, Empty, ErrorBox } from '../../components/ui.jsx';
import './organization-chart.css';

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

export function ReportingGraph({ people, links, selected, onSelect, showUnassigned = false }) {
  const [zoom, setZoom] = useState(1);
  const [collapsed, setCollapsed] = useState(new Set());
  const roots = people.filter(person => !links.some(link => same(link.user_id, person.id)));
  const visible = new Set(roots.map(person => String(person.id)));
  const queue = [...visible];
  for (let i = 0; i < queue.length; i++) {
    const id = queue[i];
    if (collapsed.has(id)) continue;
    for (const link of links.filter(item => same(item.manager_id, id))) {
      const child = String(link.user_id);
      if (!visible.has(child)) {
        visible.add(child);
        queue.push(child);
      }
    }
  }

  const levels = Object.fromEntries(people.map(person => [person.id, 0]));
  for (let i = 0; i < people.length; i++) {
    let changed = false;
    for (const link of links) {
      if (levels[link.user_id] <= levels[link.manager_id]) {
        levels[link.user_id] = Math.min(people.length, levels[link.manager_id] + 1);
        changed = true;
      }
    }
    if (!changed) break;
  }

  const counters = {};
  const positions = {};
  for (const person of people.filter(item => visible.has(String(item.id))).sort((a, b) => a.name.localeCompare(b.name))) {
    const level = levels[person.id];
    positions[person.id] = { x: 25 + level * 300, y: 25 + (counters[level] || 0) * 165 };
    counters[level] = (counters[level] || 0) + 1;
  }
  const width = Math.max(560, ...Object.values(positions).map(point => point.x + 265));
  const height = Math.max(200, ...Object.values(positions).map(point => point.y + 160));

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
    </div>
    <div className="org-canvas" tabIndex={0} aria-label="Reporting chart, scroll to explore">
      <div style={{ width: width * zoom, height: height * zoom }}>
        <div className="org-scene" style={{ width, height, transform: `scale(${zoom})` }}>
          <svg width={width} height={height} aria-hidden="true">
            {links.filter(link => positions[link.manager_id] && positions[link.user_id]).map(link => {
              const start = positions[link.manager_id];
              const end = positions[link.user_id];
              return <path key={`${link.manager_id}:${link.user_id}`} className={link.pending ? 'org-link-pending' : undefined}
                d={`M ${start.x + 240} ${start.y + 48} C ${start.x + 270} ${start.y + 48}, ${end.x - 25} ${end.y + 48}, ${end.x} ${end.y + 48}`} />;
            })}
          </svg>
          {people.filter(person => positions[person.id]).map(person => {
            const children = links.filter(link => same(link.manager_id, person.id)).length;
            const id = String(person.id);
            return <div className={`org-node org-${person.responsibility} ${same(person.id, selected) ? 'org-selected' : ''}`} key={id}
              style={{ left: positions[id].x, top: positions[id].y }}>
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
