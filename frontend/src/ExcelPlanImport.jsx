import { useEffect, useState } from 'react';
import { FileSpreadsheet, LoaderCircle } from 'lucide-react';
import { request } from './api.js';
import { Avatar, Badge, ErrorBox, Field } from './components.jsx';

const departmentRoles = { hof: 'Head of function', manager: 'Manager', employee: 'Employee' };

export default function ExcelPlanImport({ plans, onClose, onCreated, onExisting }) {
  const [options, setOptions] = useState(null);
  const [year, setYear] = useState('');
  const [department, setDepartment] = useState('');
  const [employees, setEmployees] = useState(null);
  const [employee, setEmployee] = useState('');
  const [file, setFile] = useState(null);
  const [sheets, setSheets] = useState([]);
  const [sheet, setSheet] = useState('');
  const [preview, setPreview] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const existingPlan = preview?.existing_plan ?? plans.find(plan => String(plan.owner_id) === employee && String(plan.year) === year);
  const canUpload = !!employee && !existingPlan;

  useEffect(() => {
    let active = true;
    request('/planning/excel-import/options').then(data => { if (active) setOptions(data); })
      .catch(err => { if (active) setError(err); });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!department) return;
    let active = true;
    request(`/planning/excel-import/options?department_id=${department}`)
      .then(data => { if (active) setEmployees(data.employees); })
      .catch(err => { if (active) { setError(err); setEmployees([]); } });
    return () => { active = false; };
  }, [department]);

  function resetWorkbook(nextFile = null) {
    setFile(nextFile);
    setSheets([]);
    setSheet('');
    setPreview(null);
    setError(null);
  }

  async function submit(event) {
    event.preventDefault();
    if (busy || !canUpload || !file || (sheets.length > 0 && !sheet)) return;
    setBusy(true);
    setError(null);
    const body = new FormData();
    body.set('workbook', file);
    body.set('year', year);
    body.set('department_id', department);
    body.set('owner_id', employee);
    if (sheet) body.set('sheet', sheet);
    if (preview) {
      body.set('save', '1');
    }
    try {
      const response = await request('/planning/excel-import', { method: 'POST', body });
      if (preview) {
        await onCreated(response.id);
      } else if (sheet) {
        setPreview(response);
      } else {
        setSheets(response.sheets);
        if (response.sheets.length === 1) setSheet(response.sheets[0]);
      }
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return <form onSubmit={submit}>
    <div className="modal-body">
      <ErrorBox error={error} />
      <div className="form-grid">
        <Field label="Performance year" required>
          <select value={year} disabled={busy || !options} onChange={event => {
            setYear(event.target.value);
            setDepartment('');
            setEmployees(null);
            setEmployee('');
            resetWorkbook();
          }} required>
            <option value="">{options ? 'Select year' : error ? 'Years unavailable' : 'Loading years…'}</option>
            {options?.years.map(value => <option key={value}>{value}</option>)}
          </select>
        </Field>
        <Field label="Department" required>
          <select value={department} disabled={busy || !year} onChange={event => {
            setDepartment(event.target.value);
            setEmployees(null);
            setEmployee('');
            resetWorkbook();
          }} required>
            <option value="">Select department</option>
            {options?.departments.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
        </Field>
      </div>
      {department && <fieldset className="excel-employee-picker" disabled={busy}>
        <legend>Department members{employees ? ` (${employees.length})` : ''}</legend>
        {employees === null && <p role="status">Loading department members…</p>}
        {employees?.length === 0 && !error && <p>No people are assigned to this department yet.</p>}
        {!!employees?.length && <>
          <p>Select a person to upload their workbook.</p>
          <div className="excel-employee-list">
            {employees.map(person => <label className="manager-option excel-employee-option" key={person.id}>
              <input type="radio" name="excel_employee" value={person.id} checked={employee === String(person.id)}
                disabled={person.import_block_reason !== null} onChange={event => {
                  setEmployee(event.target.value);
                  resetWorkbook();
                }} />
              <Avatar name={person.name} small />
              <span className="excel-employee-detail">
                <strong>{person.name}</strong>
                <small>{[person.employee_number && `EPF ${person.employee_number}`, person.responsibility_title || person.designation].filter(Boolean).join(' · ')}</small>
              </span>
              <Badge>{person.import_block_reason || departmentRoles[person.responsibility]}</Badge>
            </label>)}
          </div>
        </>}
      </fieldset>}
      {existingPlan && <div className="form-note">
        <span>A plan already exists for this employee and year.</span>
        <button type="button" className="btn btn-secondary" onClick={() => onExisting(existingPlan.id)}>Open existing plan</button>
      </div>}
      {canUpload && <Field label="Employee workbook" required hint={`Use a ${year} individual KPI workbook in .xlsx format, up to 5 MB.`}>
        <input key={`${year}:${department}:${employee}`} type="file" accept=".xlsx" disabled={busy} required
          onChange={event => resetWorkbook(event.target.files[0] ?? null)} />
      </Field>}
      {sheets.length > 0 && <Field label="Worksheet" required>
        <select value={sheet} disabled={busy} onChange={event => {
          setSheet(event.target.value);
          setPreview(null);
          setError(null);
        }} required>
          <option value="">Select an individual plan</option>
          {sheets.map(name => <option key={name}>{name}</option>)}
        </select>
      </Field>}
      {preview && <>
        <div className="form-note"><FileSpreadsheet size={18} /><span>
          <strong>{preview.excel_name || sheet}</strong> · EPF {preview.epf} · {preview.year}<br />
          Matched employee: {preview.employee.name} · {options.departments.find(item => String(item.id) === department)?.name}
        </span></div>
        <section className="panel">
          <header className="panel-heading"><h2>Worksheet preview</h2><Badge tone="mint">{preview.rows.length} KPIs · {preview.weight_total * 100}% allocated</Badge></header>
          <div className="table-scroll">
            <table><thead><tr><th>Ref</th><th>KPI</th><th>{year} target</th><th>Monitoring</th><th>Weight</th><th>Activities</th></tr></thead>
              <tbody>{preview.rows.map(row => <tr key={row.reference}>
                <td>{row.reference}</td><td><strong>{row.title}</strong><br />{row.category}</td><td>{row.target}</td>
                <td>{row.monitoring}</td><td>{Number((row.weight * 100).toFixed(2))}%</td><td>{row.activities.length}</td>
              </tr>)}</tbody>
            </table>
          </div>
        </section>
        <p className="form-note">Review the targets, reporting periods and activity weights in the saved draft. Monitoring codes are preserved as Excel notes.</p>
      </>}
    </div>
    <div className="modal-footer">
      <button type="button" className="btn btn-secondary" disabled={busy} onClick={onClose}>Cancel</button>
      <button className="btn btn-primary" disabled={busy || !canUpload || !file || (sheets.length > 0 && !sheet)}>
        {busy ? <LoaderCircle className="spin" size={17} /> : <FileSpreadsheet size={17} />}
        {preview ? 'Save as draft' : sheets.length ? 'Preview worksheet' : 'Read workbook'}
      </button>
    </div>
  </form>;
}
