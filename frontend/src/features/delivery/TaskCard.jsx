import Timeliness from '../../components/Timeliness.jsx';
import { ArrowRight, Building2, CalendarDays, CheckCircle2, ChevronRight, ClipboardList, Clock3, Eye, Send, Target, User, UserCheck, Users } from 'lucide-react';
import { InfoDetails } from '../../components/ui.jsx';
import { Progress, TaskBadge } from './DeliveryUi.jsx';
import { pct, editable } from './deliveryFormat.js';
import './task-cards.css';

export default function TaskCard({ task, reviewing, person, department, submissions, onAction, readOnly = false }) {
  const confirmed = Number(Number(task.confirmed_progress || 0).toFixed(2));
  const due = task.timeliness?.due_date || task.due_date || task.parent.due_date;
  const todayParts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Colombo', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
  const part = type => todayParts.find(value => value.type === type).value;
  const today = `${part('year')}-${part('month')}-${part('day')}`;
  const days = due ? Math.round((Date.parse(due.slice(0, 10) + 'T00:00:00Z') - Date.parse(today + 'T00:00:00Z')) / 86400000) : null;
  const overdue = days !== null && days < 0 && confirmed < 100;
  const dueSoon = days !== null && days >= 0 && days <= 3 && confirmed < 100;
  const dueText = due ? new Date(due.slice(0, 10) + 'T00:00:00Z').toLocaleDateString('en-GB', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' }) : 'Not set';
  const dueHint = confirmed >= 100 ? 'Work completed' : days === null ? 'No deadline assigned' : days < 0 ? `${Math.abs(days)} day${days === -1 ? '' : 's'} overdue` : days === 0 ? 'Due today' : `Due in ${days} day${days === 1 ? '' : 's'}`;
  return <article className={`task-card task-card--${task.status}`}>
    <div className="task-card-content">
      <div className="task-card-topline"><span className="task-card-type"><ClipboardList size={15} /> TASK #{task.id}</span><TaskBadge task={task} /><span className="task-card-department"><Building2 size={14} />{department(task.parent.department_id)}</span></div>
      <h3>{task.title}</h3>
      <div className="task-card-context"><span>Parent work</span><ChevronRight size={14} /><strong>{task.parent.activity}</strong></div>
      <div className="task-card-facts">
        <div className="task-card-fact"><span className="task-card-fact-icon"><User size={18} /></span><div><small>Task owner</small><strong>{person(task.assignee_id)}</strong></div></div>
        <div className="task-card-fact"><span className="task-card-fact-icon"><UserCheck size={18} /></span><div><small>{task.self_confirm ? 'Completion approval' : 'Reviewer'}</small><strong>{task.self_confirm ? 'HOF self confirmation' : person(task.reviewer_id)}</strong></div></div>
        <div className={`task-card-fact task-card-deadline ${overdue ? 'is-overdue' : dueSoon ? 'is-due-soon' : ''}`}><span className="task-card-fact-icon"><CalendarDays size={18} /></span><div><small>Deadline</small><strong>{dueText}</strong><span><Clock3 size={12} />{dueHint}</span></div></div>
      </div>
      {(task.instructions || task.progress_note) && <InfoDetails title="Instructions & progress notes"><div className="task-card-notes">{task.instructions && <div><small>INSTRUCTIONS</small><p>{task.instructions}</p></div>}{task.progress_note && <div><small>LATEST PROGRESS UPDATE</small><p>{task.progress_note}</p></div>}</div></InfoDetails>}
      {task.status === 'needs_changes' && submissions[0]?.review_note && <div className="task-card-feedback"><strong>Changes requested</strong><p>{submissions[0].review_note}</p></div>}
      {reviewing && submissions[0] && <div className="task-card-notes"><div><small>SUBMITTED EVIDENCE</small><p>{submissions[0].evidence}</p></div></div>}
      {task.has_children && <details className="task-card-children"><summary><Users size={16} />Work breakdown <span>{task.children.length} subtask{task.children.length === 1 ? '' : 's'}</span><ChevronRight size={16} /></summary><div>{task.children.map(child => <div className="task-card-child" key={child.id}><div><strong>{child.title}</strong><small>{person(child.assignee_id)}</small></div><span>{pct(child.weight)}<small>of this task</small></span><span>{Number(child.confirmed_progress)}%<small>confirmed</small></span></div>)}</div></details>}
      <div className="task-card-footnote"><Target size={15} /><span><strong>{pct(task.weight)}</strong> of parent work</span></div>
    </div>
    <aside className="task-card-sidebar" aria-label={`Progress and actions for ${task.title}`}>
      <div className="task-card-score"><div className="task-card-score-heading"><span>CONFIRMED COMPLETION</span><CheckCircle2 size={18} /></div><strong className="task-card-score-value">{confirmed}<span>%</span></strong><Progress label="Confirmed" value={confirmed} approved /><Progress label="Reported progress" value={task.reported_progress} /><p>{task.has_children ? 'Calculated from approved subtasks.' : task.self_confirm ? 'Your confirmation records completed work.' : 'Confirmed after reviewer approval.'}</p></div>
      <Timeliness value={task.timeliness} /><div className="task-card-actions">
        {task.can_extend_deadline && <button className="btn btn-secondary" onClick={() => onAction('deadline')}>Extend deadline<CalendarDays size={15} /></button>}
        {reviewing ? <button className="btn btn-primary" onClick={() => onAction('review')}>Review submission<ArrowRight size={16} /></button> : !readOnly && editable(task) && <><button className="btn btn-primary" onClick={() => onAction('submit')}><Send size={16} />{task.self_confirm ? 'Confirm completion' : 'Submit completed work'}</button><button className="btn btn-secondary" onClick={() => onAction('progress')}>Update progress<ArrowRight size={15} /></button></>}
        {!reviewing && !readOnly && <button className="btn btn-secondary" disabled={!task.has_children && task.breakdown_locked} onClick={() => onAction('breakdown')}><Users size={16} />{task.has_children ? 'View work breakdown' : 'Divide work'}</button>}
        <button className="text-button task-card-history" onClick={() => onAction('history')}><Eye size={16} />Evidence & review history</button>
      </div>
    </aside>
  </article>;
}
