import { useState } from 'react';
import { ChevronRight, ClipboardList, Pencil, Search, Users } from 'lucide-react';
import { Avatar, Badge, Empty } from '../../components/ui.jsx';
import './delegated-work.css';

const same = (a, b) => String(a) === String(b);
const percent = value => `${Number((Number(value) * 100).toFixed(2))}%`;
const approved = row => row.source_changed || !row.assignee_eligible ? 0 : Number(row.assignment?.confirmed_progress || 0);

function PersonWork({ group, departmentOf, onAssign, initiallyOpen }) {
  const [open, setOpen] = useState(initiallyOpen);
  const share = group.rows.reduce((sum, row) => sum + row.personal_weight, 0);
  const completion = share ? group.rows.reduce((sum, row) => sum + row.personal_weight * approved(row), 0) / share : 0;
  const fullyApproved = group.rows.filter(row => approved(row) >= 100).length;
  const review = group.rows.filter(row => row.source_changed || !row.assignee_eligible).length;
  const kpis = new Set(group.rows.map(row => `${row.plan_id}:${row.kpi_code}`)).size;
  return <details className="hof-person-work" open={open} onToggle={event => { if (event.target === event.currentTarget) setOpen(event.currentTarget.open); }}>
    <summary>
      <div className="hof-assignee-identity"><Avatar name={group.person?.name || 'Previous owner'} /><div><small>ASSIGNED TO</small><h2>{group.person?.name || 'Previous owner'}</h2><p>{group.person?.employee_number ? `EPF ${group.person.employee_number}` : `Account ${group.id}`}{group.person?.designation ? ` · ${group.person.designation}` : ''}</p></div></div>
      <div className="hof-assignee-counts"><strong>{group.rows.length}<span> {group.rows.length === 1 ? 'activity' : 'activities'}</span></strong><small>{kpis} {kpis === 1 ? 'KPI' : 'KPIs'} · {fullyApproved} fully approved</small><small>{percent(share)} of your plan</small>{review > 0 && <Badge tone="draft">{review} need review</Badge>}</div>
      <div className="hof-assignee-progress"><strong>{Number(completion.toFixed(2))}%</strong><small>Approved work</small><span className="hof-assignee-track"><span style={{ width: `${Math.min(100, Math.max(0, completion))}%` }} /></span></div>
      <ChevronRight className="hof-assignee-chevron" size={20} />
    </summary>
    <div className="hof-person-activities">{group.rows.map(row => <article className="hof-person-activity" key={`${row.plan_id}:${row.activity_code}`}>
      <div className="hof-person-activity-copy"><div className="hof-person-activity-labels"><Badge>{departmentOf(row.department_id)?.code || 'Department'}</Badge><small>{row.activity_code}</small>{(row.source_changed || !row.assignee_eligible) && <Badge tone="draft">Review assignment</Badge>}</div><h3>{row.activity}</h3><p>{row.kpa_title} <ChevronRight size={12} /> {row.kpi_title}</p><small>{percent(row.personal_weight)} of your plan · {percent(row.activity_weight)} within KPI · {row.assignment.due_date ? `Due ${row.assignment.due_date}` : 'No assignment deadline'}</small></div>
      <div className="hof-person-activity-action"><strong>{approved(row)}% <span>approved</span></strong><small>{Number(row.assignment.reported_progress || 0)}% reported</small><button type="button" className="btn btn-secondary btn-small" onClick={() => onAssign(row)}><Pencil size={14} />Edit assignment</button></div>
    </article>)}</div>
  </details>;
}

export default function DelegatedWork({ rows, people, departmentOf, query, onQuery, onAssign }) {
  const [personFilter, setPersonFilter] = useState('all');
  const groups = [];
  for (const row of rows) {
    const id = String(row.assignment.assignee_id);
    let group = groups.find(item => item.id === id);
    if (!group) { group = { id, person: people.find(item => same(item.id, id)), rows: [] }; groups.push(group); }
    group.rows.push(row);
  }
  groups.sort((a, b) => (a.person?.name || '').localeCompare(b.person?.name || ''));
  const selected = groups.some(group => group.id === personFilter) ? personFilter : 'all';
  const needle = query.trim().toLowerCase();
  const visible = groups.filter(group => selected === 'all' || group.id === selected).map(group => {
    const personMatches = `${group.person?.name || ''} ${group.person?.employee_number || ''} ${group.person?.designation || ''}`.toLowerCase().includes(needle);
    return { ...group, rows: group.rows.filter(row => personMatches || [row.activity, row.activity_code, row.kpa_title, row.kpi_title, departmentOf(row.department_id)?.name].some(value => String(value || '').toLowerCase().includes(needle))) };
  }).filter(group => group.rows.length);
  const shown = visible.reduce((sum, group) => sum + group.rows.length, 0);
  return <section className="hof-delegated-work">
    <div className="hof-delegated-heading"><div><h2>Work by person</h2><p>Open a person to see their assigned activities together.</p></div><span><Users size={16} />{groups.length} people · {rows.length} activities</span></div>
    <div className="hof-delegated-filters"><label className="hof-delegated-search"><Search size={18} /><input aria-label="Search delegated work" placeholder="Search name, EPF, activity or KPI…" value={query} onChange={event => onQuery(event.target.value)} /></label>
      <select aria-label="Assigned person" value={selected} onChange={event => { setPersonFilter(event.target.value); onQuery(''); }}><option value="all">All assigned people</option>{groups.map(group => <option key={group.id} value={group.id}>{group.person?.employee_number || group.id} · {group.person?.name || 'Previous owner'}</option>)}</select>
      <small>{shown} activities shown</small>
    </div>
    {!visible.length ? <section className="panel"><Empty icon={ClipboardList} title={rows.length ? 'No matching delegated work' : 'No delegated work yet'} text={rows.length ? 'Search another name, EPF or activity, or choose another person.' : 'Assign a KPI or activity from Plan and assignments.'} /></section>
      : visible.map(group => <PersonWork key={`${selected}:${group.id}`} group={group} initiallyOpen={selected !== 'all'} departmentOf={departmentOf} onAssign={onAssign} />)}
  </section>;
}
