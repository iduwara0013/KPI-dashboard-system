import { useEffect, useState } from 'react';
import { ClipboardList, LoaderCircle, RefreshCw, Search, Target } from 'lucide-react';
import { request } from '../../services/api.js';
import { Badge, Empty, ErrorBox, Modal } from '../../components/ui.jsx';
import MixedAssignmentForm from '../delivery/MixedAssignmentForm.jsx';
import BreakdownForm from '../delivery/BreakdownForm.jsx';
import { same, pct } from '../delivery/deliveryFormat.js';
import './plan-work.css';

export default function PlanWorkWorkspace({ onOpenPlan }) {
  const [data, setData] = useState(null), [error, setError] = useState(null), [modal, setModal] = useState(null);
  const [department, setDepartment] = useState(''), [query, setQuery] = useState(''), [year, setYear] = useState('');
  const [message, setMessage] = useState(''), [loading, setLoading] = useState(false);
  async function load() {
    setLoading(true);
    try { setData(await request('/planning/work')); setError(null); }
    catch (err) { setError(err); }
    finally { setLoading(false); }
  }
  useEffect(() => { load(); }, []);
  async function saved(text) { await load(); setModal(null); setMessage(text); }
  const person = id => data?.people.find(row => same(row.id, id));
  const name = id => { const found = person(id); return found ? `${found.employee_number} · ${found.name}` : 'Not assigned'; };
  const matches = row => (!department || same(department, row.department_id)) && (!year || same(year, row.year))
    && `${row.kpi_title} ${row.kpa_title} ${row.plan_code} ${name(row.owner_id)} ${name(row.delegation?.assignee_id)}`.toLowerCase().includes(query.trim().toLowerCase());
  function recipients(row) {
    const members = data.people.filter(item => same(item.department_id, row.department_id));
    return {
      managers: members.filter(item => item.responsibility === 'manager' && data.reporting.some(link => same(link.department_id, row.department_id) && same(link.manager_id, row.owner_id) && same(link.user_id, item.id))),
      employees: members.filter(item => item.responsibility === 'employee' && reachable(item.id, row.owner_id, row.department_id)),
    };
  }
  function reachable(id, owner, departmentId) {
    const pending = [owner], seen = new Set();
    for (let index = 0; index < pending.length; index++) {
      if (seen.has(String(pending[index]))) continue;
      seen.add(String(pending[index]));
      for (const link of data.reporting.filter(link => same(link.department_id, departmentId) && same(link.manager_id, pending[index]))) {
        if (same(link.user_id, id)) return true;
        pending.push(link.user_id);
      }
    }
    return false;
  }
  function branches(parent) {
    if (!parent) return null;
    const tasks = data.breakdowns.filter(row => row.task_branch && same(row.assignment_id, parent.id));
    function list(parentId = null) {
      return tasks.filter(row => same(row.parent_task_id ?? '', parentId ?? '')).map(task => <li key={task.id}>
        <div className="plan-work-task"><div><strong>{task.activity}</strong><small>{name(task.responsible_id)} · {pct(task.weight)} of parent · {pct(task.personal_weight)} of original plan · {Number(task.confirmed_progress || 0)}% approved · {task.status}</small></div>
          {task.can_edit && <button className="btn btn-secondary btn-small" disabled={loading} onClick={() => setModal({ kind: 'breakdown', row: task })}>{task.breakdown_locked ? 'View breakdown' : task.tasks.length ? 'Edit subtasks' : 'Divide work'}</button>}
        </div>{task.tasks.length > 0 && <ul>{list(task.id)}</ul>}
      </li>);
    }
    return <div className="plan-work-breakdown"><div className="plan-work-task"><span>Activity owner: <strong>{name(parent.responsible_id)}</strong></span>
      {parent.can_edit && <button className="btn btn-secondary btn-small" disabled={loading} onClick={() => setModal({ kind: 'breakdown', row: parent })}>{parent.breakdown_locked ? 'View task breakdown' : parent.tasks.length ? 'Edit task breakdown' : 'Add tasks'}</button>}
    </div>{tasks.length > 0 && <ul>{list()}</ul>}</div>;
  }
  const years = [...new Set((data?.kpis || []).map(row => row.year))].sort((a, b) => b - a);
  const responsible = modal && person(modal.kind === 'breakdown' ? modal.row.responsible_id : modal.row.owner_id);
  const employees = modal && data.people.filter(item => same(item.department_id, modal.row.department_id) && !same(item.id, responsible?.id)
    && data.reporting.some(link => same(link.department_id, modal.row.department_id) && same(link.user_id, item.id) && same(link.manager_id, responsible?.id)));
  return <>
    <div className="page-heading page-heading-compact"><h1>Work assignments</h1><button className="btn btn-secondary" disabled={loading} onClick={load}><RefreshCw size={16} />Refresh</button></div>
    <ErrorBox error={error} />{message && <p className="form-note" role="status">{message}</p>}
    <div className="plan-filters"><label className="plan-search"><Search size={17} /><input aria-label="Search work assignments" placeholder="Search KPI, name or EPF" value={query} onChange={event => setQuery(event.target.value)} /></label>
      <select aria-label="Work department" value={department} onChange={event => setDepartment(event.target.value)}><option value="">My editor departments</option>{data?.departments.map(row => <option key={row.id} value={row.id}>{row.name}</option>)}</select>
      <select aria-label="Work year" value={year} onChange={event => setYear(event.target.value)}><option value="">All years</option>{years.map(value => <option key={value}>{value}</option>)}</select>
    </div>
    {!data ? loading ? <p><LoaderCircle className="spin" />Loading assignments…</p> : null : !data.kpis.filter(matches).length ? <Empty icon={ClipboardList} title="No published work in this view" text="Create and publish a department plan to assign its KPIs and activities." /> : data.kpis.filter(matches).map(kpi => <section className="panel plan-work-kpi" key={`${kpi.plan_id}:${kpi.kpi_code}`}>
      <div className="panel-heading"><div><small>{kpi.plan_code} · {kpi.year} · {data.departments.find(row => same(row.id, kpi.department_id))?.name}</small><h2><Target size={18} />{kpi.kpi_title}</h2><p>Plan owner: {name(kpi.owner_id)} · {pct(kpi.personal_weight)} of their plan</p></div>
        <div className="heading-actions">{onOpenPlan && <button className="btn btn-secondary" onClick={() => onOpenPlan(kpi.plan_id)}>{kpi.can_edit ? 'Edit plan / add activities' : 'View plan'}</button>}{kpi.can_edit ? <button className="btn btn-secondary" disabled={loading} onClick={() => setModal({ kind: 'kpi', row: kpi })}>{kpi.delegation ? 'Edit KPI assignment' : 'Assign KPI'}</button> : <Badge>Department view only</Badge>}</div></div>
      <div className="plan-work-link"><span>Parent KPI</span><strong>{kpi.kpi_title}</strong><span>Recipient’s linked KPA</span><strong>{kpi.delegation ? name(kpi.delegation.assignee_id) : 'Not assigned'}</strong>
        {kpi.delegation && <Badge tone={kpi.valid ? 'mint' : 'draft'}>{kpi.valid ? `${kpi.confirmed_progress}% approved` : 'Assignment needs review'}</Badge>}</div>
      <details><summary>Activities & task breakdowns ({kpi.activities.length})</summary>{data.activities.filter(row => row.plan_id === kpi.plan_id && row.kpi_code === kpi.kpi_code).map(row => {
        const parent = row.assignment && data.breakdowns.find(item => !item.task_branch && same(item.id, row.assignment.id));
        return <article className="plan-work-activity" key={row.activity_code}><div className="plan-work-task"><div><h3>{row.activity}</h3><small>{pct(row.activity_weight)} within KPI · {pct(row.personal_weight)} of original plan · {Number(row.assignment?.confirmed_progress || 0)}% approved</small></div>
          {row.can_edit && <button className="btn btn-secondary btn-small" disabled={loading} onClick={() => setModal({ kind: 'activity', row })}>{row.assignment ? 'Edit activity assignment' : 'Assign activity'}</button>}</div>
          {(row.source_changed || !row.assignee_eligible) && <p className="delivery-warning">Source or reporting changed. Review the assignment.</p>}{branches(parent)}</article>;
      })}</details>
    </section>)}
    {modal && responsible && <Modal title={modal.kind === 'breakdown' ? 'Task breakdown' : modal.kind === 'kpi' ? 'KPI assignment' : 'Activity assignment'} subtitle={modal.row.activity || modal.row.kpi_title} onClose={() => setModal(null)}>
      {modal.kind === 'breakdown' ? <BreakdownForm parent={modal.row} data={{ employees }} session={responsible} planning apiBase="/planning/work" onClose={() => setModal(null)} onSaved={saved} />
        : <MixedAssignmentForm kind={modal.kind} row={modal.row} {...recipients(modal.row)} session={responsible} planning apiBase="/planning/work" onClose={() => setModal(null)} onSaved={saved} />}
    </Modal>}
  </>;
}
