import { useState } from 'react';
import { ChevronRight, ClipboardList, Eye, FolderTree, Plus, Target } from 'lucide-react';
import Timeliness from '../../components/Timeliness.jsx';
import { Badge, Empty, InfoDetails } from '../../components/ui.jsx';
import { Progress, TaskBadge } from './DeliveryUi.jsx';
import { pct, same } from './deliveryFormat.js';
import { assignedWeights } from './assignedWeights.js';
import './work-structure.css';

function Level({ className, summary, children, initiallyOpen }) {
  const [open, setOpen] = useState(initiallyOpen);
  return <details className={className} open={open} onToggle={event => { if (event.target === event.currentTarget) setOpen(event.currentTarget.open); }}><summary>{summary}<ChevronRight className="work-level-chevron" size={19} /></summary>{children}</details>;
}

function Activity({ parent, weights, person, onAction, initiallyOpen }) {
  return <article className="delivery-parent"><Level className="assigned-activity" initiallyOpen={initiallyOpen} summary={<>
    <ClipboardList size={18} /><div className="work-level-title"><small>ACTIVITY</small><h4>{parent.activity}</h4><span>{parent.tasks.length} tasks · {parent.due_date ? `Due ${parent.due_date}` : 'No deadline'}</span></div>
    <div className="work-level-score"><strong>{pct(weights.activity)}</strong><small>within my assigned KPI</small></div><div className="work-level-score work-approved"><strong>{Number(parent.confirmed_progress)}%</strong><small>approved</small></div>
  </>}><div className="assigned-activity-body">
    <div className="assigned-activity-actions"><span>{pct(weights.overall)} of my assigned work{parent.assigned_by_id ? ` · Assigned by ${person(parent.assigned_by_id)}` : ''}</span>{parent.valid && !parent.breakdown_locked && !parent.tasks.length && <button className="btn btn-secondary" onClick={() => onAction('activity-owner', parent)}>Assign activity / keep with me</button>}<button className="btn btn-secondary" disabled={!parent.valid} onClick={() => onAction('breakdown', parent)}>{parent.breakdown_locked ? <Eye size={16} /> : <Plus size={16} />}{parent.breakdown_locked ? 'View tasks' : parent.tasks.length ? 'Edit task breakdown' : 'Create task breakdown'}</button></div>
    {parent.instructions && <InfoDetails title="Instructions"><p className="delivery-instructions">{parent.instructions}</p></InfoDetails>}
    {!parent.valid ? <div className="delivery-warning">This activity or reporting relationship changed. Ask the HOF to review its assignment before continuing.</div> : <>
      <div className="delivery-parent-progress"><Progress label="Reported" value={parent.reported_progress} /><Progress label="Approved completion" value={parent.confirmed_progress} approved /><Timeliness value={parent.timeliness} compact /><span>{parent.allocation_ready ? '100% allocated' : 'Breakdown needed'}</span></div>
      {parent.tasks.length > 0 && <InfoDetails title={`Tasks (${parent.tasks.length})`}><div className="delivery-task-preview">{parent.tasks.map(task => <div className="deadline-preview-action" key={task.id}><button onClick={() => onAction('history', parent, task)}><span>{task.title}<small>{person(task.assignee_id)}</small></span><strong>{pct(task.weight)}</strong><TaskBadge task={task} /></button>{task.can_extend_deadline && <button className="text-button" onClick={() => onAction('deadline', parent, task)}>Extend deadline</button>}</div>)}</div></InfoDetails>}
    </>}
  </div></Level></article>;
}

export default function AssignedActivities({ rows, allRows = rows, delegatedKpis, person, department, query, onAction }) {
  const weights = assignedWeights(allRows);
  const areas = new Map();
  for (const row of rows) {
    const areaKey = JSON.stringify([row.plan_id, row.kpa_code || row.kpa_title, row.owner_id, row.year]);
    if (!areas.has(areaKey)) areas.set(areaKey, { key: areaKey, row, kpis: new Map() });
    const area = areas.get(areaKey);
    const kpiKey = row.kpi_code || row.kpi_title;
    if (!area.kpis.has(kpiKey)) area.kpis.set(kpiKey, { key: kpiKey, row, rows: [] });
    area.kpis.get(kpiKey).rows.push(row);
  }
  const linkedTo = (row, kpi) => row.linked_kpa_key ? row.linked_kpa_key === kpi.link_key : same(row.delegation_id, kpi.delegation.id);
  const linkedCount = delegatedKpis.filter(kpi => rows.some(row => linkedTo(row, kpi))).length;
  return <section className="assigned-structure">
    <div className="assigned-structure-heading"><h2>Assigned activities <span className="count-label">{rows.length}</span></h2><span>{linkedCount} linked KPAs · {areas.size} source KPAs</span></div>
    {!rows.length ? <section className="panel"><Empty icon={ClipboardList} title="No assigned activities in this scope" text="Assigned KPIs and activities appear here under their source plan." /></section> : [...areas.values()].map(area => <Level key={`${Boolean(query)}:${area.key}`} className="assigned-area" initiallyOpen={Boolean(query)} summary={<>
      <FolderTree size={23} /><div className="work-level-title"><small>SOURCE KPA</small><h3>{area.row.kpa_title || 'Previous plan'}</h3><span>{department(area.row.department_id)} · {area.row.year} · HOF: {person(area.row.owner_id)}</span></div><Badge>{area.kpis.size} {area.kpis.size === 1 ? 'KPI' : 'KPIs'}</Badge><div className="work-level-score work-source-weight"><strong>{pct(weights.get(area.row).kpa)}</strong><small>of my assigned work</small></div>
    </>}><div className="assigned-kpis">{[...area.kpis.values()].map(kpi => {
      const linked = delegatedKpis.find(item => kpi.rows.some(row => linkedTo(row, item)));
      return <Level key={kpi.key} className="assigned-kpi" initiallyOpen={Boolean(query)} summary={<>
        <Target size={20} /><div className="work-level-title"><small>{linked ? 'SOURCE KPI → MY LINKED KPA' : 'SOURCE KPI · ACTIVITY ASSIGNMENT'}</small><h3>{kpi.row.kpi_title}</h3><span>{kpi.rows.length} assigned activities{linked && !linked.valid ? ' · Delegation needs review' : ''}</span></div>{linked && <Badge>Linked KPA</Badge>}<div className="work-level-score work-source-weight"><strong>{pct(weights.get(kpi.row).kpi)}</strong><small>within my assigned KPA</small></div>
      </>}><div>{kpi.rows.map(parent => <Activity key={parent.id} parent={parent} weights={weights.get(parent)} person={person} onAction={onAction} initiallyOpen={Boolean(query)} />)}</div></Level>;
    })}</div></Level>)}
  </section>;
}
