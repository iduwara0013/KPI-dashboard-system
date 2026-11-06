import { useEffect, useRef, useState } from 'react';
import { Network, Save } from 'lucide-react';
import { request } from './api.js';
import { Avatar, Empty, ErrorBox, Field } from './components.jsx';
import { ReportingGraph, reportingRoles } from './OrganizationChart.jsx';

function wouldCreateCycle(links, managerId, userId) {
  const pending = [managerId];
  const seen = new Set();
  for (const id of pending) {
    if (id === userId) return true;
    if (seen.has(id)) continue;
    seen.add(id);
    for (const link of links) {
      if (link.user_id === id) pending.push(link.manager_id);
    }
  }
  return false;
}

export default function DepartmentReporting({ data, onSaved }) {
  const departments = data.departments.filter(department => department.is_active);
  const [departmentId, setDepartmentId] = useState(() => String(departments[0]?.id || ''));
  const [selected, setSelected] = useState(null);
  const [draft, setDraft] = useState({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [message, setMessage] = useState('');
  const editor = useRef(null);
  const people = data.memberships.filter(membership => String(membership.department_id) === departmentId)
    .flatMap(membership => {
      const user = data.users.find(person => Number(person.id) === Number(membership.user_id));
      return user ? [{
        id: Number(user.id), name: user.name, employee_number: user.employee_number, is_active: user.is_active,
        designation: user.designation, responsibility: membership.responsibility, responsibility_title: membership.responsibility_title,
      }] : [];
    }).sort((a, b) => a.name.localeCompare(b.name));
  const saved = data.reporting.filter(link => String(link.department_id) === departmentId)
    .map(link => ({ user_id: Number(link.user_id), manager_id: Number(link.manager_id) }));
  const savedManagers = id => saved.filter(link => link.user_id === id).map(link => link.manager_id).sort((a, b) => a - b);
  const pending = Object.keys(draft);
  const dirty = pending.length > 0;
  const links = [...saved.filter(link => !Object.hasOwn(draft, link.user_id)), ...pending.flatMap(id =>
    draft[id].managerIds.map(managerId => ({ user_id: Number(id), manager_id: managerId, pending: !draft[id].expected.includes(managerId) })))];
  const person = people.find(item => item.id === selected);
  const currentManagers = person ? draft[person.id]?.managerIds ?? savedManagers(person.id) : [];
  const candidates = people.filter(item => item.id !== selected && (
    currentManagers.includes(item.id) || (item.is_active && ['hof', 'manager'].includes(item.responsibility))
  ));

  useEffect(() => {
    if (!dirty) return;
    function confirmLeave(event) {
      event.preventDefault();
      event.returnValue = '';
    }
    window.addEventListener('beforeunload', confirmLeave);
    return () => window.removeEventListener('beforeunload', confirmLeave);
  }, [dirty]);

  function chooseManager(managerId, checked) {
    const next = checked ? [...currentManagers, managerId].sort((a, b) => a - b) : currentManagers.filter(id => id !== managerId);
    setDraft(previous => {
      const changes = { ...previous };
      const original = previous[person.id]?.expected ?? savedManagers(person.id);
      if (next.length === original.length && next.every((id, index) => id === original[index])) delete changes[person.id];
      else changes[person.id] = { managerIds: next, expected: original };
      return changes;
    });
    setError(null);
    setMessage('');
  }

  async function save() {
    setBusy(true);
    setError(null);
    setMessage('');
    try {
      const result = await request(`/admin/departments/${departmentId}/reporting`, {
        method: 'PUT', body: { changes: pending.map(id => ({
          user_id: Number(id), manager_ids: draft[id].managerIds, expected_manager_ids: draft[id].expected,
        })) },
      });
      onSaved(departmentId, result.links);
      setDraft({});
      setMessage('Reporting changes saved.');
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return <section className="panel org-chart department-reporting">
    <div className="panel-heading"><div>
      <h2><Network size={19} /> Reporting structure</h2>
      <p>Choose a department, select a person’s card and choose who they report to.</p>
    </div></div>
    <ErrorBox error={error} />
    {message && <p className="org-note" role="status">{message}</p>}
    <div className="org-toolbar">
      <label>Department<select value={departmentId} disabled={dirty || busy} onChange={event => {
        setDepartmentId(event.target.value);
        setSelected(null);
        setMessage('');
        setError(null);
      }}>
        <option value="">Select department</option>
        {departments.map(department => <option key={department.id} value={department.id}>{department.name}</option>)}
      </select></label>
      <span>{people.length} people · {links.length} reporting links</span>
      {dirty && <div className="reporting-actions">
        <button className="btn btn-secondary" disabled={busy} onClick={() => { setDraft({}); setError(null); }}>Discard changes</button>
        <button className="btn btn-primary" disabled={busy} onClick={save}><Save size={16} /> {busy ? 'Saving…' : 'Save changes'}</button>
      </div>}
    </div>
    {dirty && <p className="org-note" role="status">Unsaved changes for {pending.length} {pending.length === 1 ? 'person' : 'people'}. New lines are dashed. Save or discard to switch departments.</p>}
    {!people.length ? <Empty icon={Network} title={departmentId ? 'No members in this department' : 'Choose an active department'} text="Department assignments are managed from People." /> : <>
      <div className="reporting-editor" ref={editor}>
        <Field label="Find a department member">
          <select value={selected ?? ''} onChange={event => setSelected(event.target.value ? Number(event.target.value) : null)}>
            <option value="">Select a person</option>
            {people.map(item => <option key={item.id} value={item.id}>{item.name}{item.employee_number ? ` · ${item.employee_number}` : ''}</option>)}
          </select>
        </Field>
        {person && <div className="reporting-person">
          <div className="org-details"><Avatar name={person.name} /><div>
            <strong>{person.name}</strong>
            <p>{[person.employee_number, person.responsibility_title || person.designation, reportingRoles[person.responsibility]].filter(Boolean).join(' · ')}</p>
            <small>Reports to: {currentManagers.map(id => people.find(item => item.id === id)?.name ?? 'Former member').join(', ') || 'Unassigned'}</small>
          </div></div>
          <fieldset disabled={busy} className="reporting-manager-picker">
            <legend>Reports to</legend>
            <div className="manager-options">
              {candidates.map(manager => {
                const checked = currentManagers.includes(manager.id);
                const createsCycle = !checked && wouldCreateCycle(links, manager.id, person.id);
                return <label key={manager.id} className="manager-option">
                  <input type="checkbox" checked={checked} disabled={!checked && (createsCycle || currentManagers.length >= 10)}
                    onChange={event => chooseManager(manager.id, event.target.checked)} />
                  {manager.name}{createsCycle && ' · Circular reporting'}
                </label>;
              })}
              {!candidates.length && <small>No other active HOF or manager in this department.</small>}
              {currentManagers.filter(id => !people.some(item => item.id === id)).map(id =>
                <label key={id} className="manager-option"><input type="checkbox" checked onChange={() => chooseManager(id, false)} />Former member</label>)}
            </div>
          </fieldset>
        </div>}
      </div>
      <ReportingGraph key={departmentId} people={people} links={links.filter(link => people.some(item => item.id === link.manager_id))} showUnassigned
        selected={selected} onSelect={id => { setSelected(id); editor.current?.scrollIntoView({ block: 'nearest' }); }} />
    </>}
  </section>;
}
