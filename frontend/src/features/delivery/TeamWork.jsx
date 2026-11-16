import { useState } from 'react';
import { ChevronRight, Eye, Users } from 'lucide-react';
import { Avatar, Badge, Empty } from '../../components/ui.jsx';
import { TaskBadge } from './DeliveryUi.jsx';
import { pct, same } from './deliveryFormat.js';
import './team-work.css';

function PersonWork({ group, person, department, initiallyOpen, onHistory }) {
  const [open, setOpen] = useState(initiallyOpen);
  const sources = new Map();
  for (const task of group.tasks) {
    const parent = task.parent;
    const key = JSON.stringify([parent.plan_id, parent.kpa_code || parent.kpa_title, parent.kpi_code || parent.kpi_title, parent.department_id, parent.year]);
    if (!sources.has(key)) sources.set(key, { key, parent, tasks: [] });
    sources.get(key).tasks.push(task);
  }
  const approved = group.tasks.filter(task => task.parent.valid && task.assignee_eligible && Number(task.confirmed_progress) >= 100).length;
  return <details className="team-person-work" open={open} onToggle={event => { if (event.target === event.currentTarget) setOpen(event.currentTarget.open); }}>
    <summary><Avatar name={person(group.id)} /><div className="team-person-name"><h3>{person(group.id)}</h3><small>{group.record?.employee_number ? `EPF ${group.record.employee_number}` : 'Work owner'}{group.record?.designation ? ` · ${group.record.designation}` : ''}</small></div><div className="team-person-count"><strong>{group.tasks.length} tasks</strong><small>{approved} approved · {group.tasks.length - approved} open</small></div><ChevronRight size={20} /></summary>
    <div className="team-person-sources">{[...sources.values()].map(source => <section className="team-work-source" key={source.key}>
      <header><div><small>SOURCE KPA</small><strong>{source.parent.kpa_title || 'Previous plan'}</strong></div><ChevronRight size={16} /><div><small>SOURCE KPI</small><strong>{source.parent.kpi_title || 'Previous KPI'}</strong></div><span>{department(source.parent.department_id)} · {source.parent.year}</span></header>
      {source.tasks.map(task => <article className="team-work-item" key={task.id}>
        <div className="team-work-copy"><button className="text-button" onClick={() => onHistory(task)}>{task.title}</button>{task.title !== task.parent.activity && <small>Activity: {task.parent.activity}</small>}<TaskBadge task={task} />{(!task.parent.valid || !task.assignee_eligible) && <Badge tone="draft">Reporting / assignment needs review</Badge>}</div>
        <div className="team-work-value"><strong>{pct(task.weight)}</strong><small>of parent work</small></div><div className="team-work-value"><strong>{Number(task.reported_progress)}%</strong><small>reported</small></div><div className="team-work-value team-work-approved"><strong>{task.parent.valid && task.assignee_eligible ? Number(task.confirmed_progress || 0) : 0}%</strong><small>approved</small></div>
        <button className="icon-button" aria-label={`Review history for ${task.title}`} onClick={() => onHistory(task)}><Eye size={18} /></button>
      </article>)}
    </section>)}</div>
  </details>;
}

export default function TeamWork({ tasks, people, person, department, query, onHistory }) {
  const [owner, setOwner] = useState('all');
  const groups = new Map();
  for (const task of tasks) {
    const id = String(task.assignee_id);
    if (!groups.has(id)) groups.set(id, { id, record: people.find(row => same(row.id, id)), tasks: [] });
    groups.get(id).tasks.push(task);
  }
  const selected = groups.has(owner) ? owner : 'all';
  const sorted = [...groups.values()].sort((a, b) => person(a.id).localeCompare(person(b.id)));
  return <section className="team-work">
    <div className="team-work-heading"><h2>Work by person <span className="count-label">{tasks.length}</span></h2><label>Owner<select aria-label="Team work owner" value={selected} onChange={event => setOwner(event.target.value)}><option value="all">All owners</option>{sorted.map(group => <option key={group.id} value={group.id}>{person(group.id)}</option>)}</select></label></div>
    {!tasks.length ? <section className="panel"><Empty icon={Users} title="No team tasks in this scope" text="Assign work or change your search and year filter." /></section> : sorted.filter(group => selected === 'all' || group.id === selected).map(group => <PersonWork key={`${selected}:${Boolean(query)}:${group.id}`} group={group} person={person} department={department} initiallyOpen={selected !== 'all' || Boolean(query)} onHistory={onHistory} />)}
  </section>;
}
