import { useId, useMemo, useState } from 'react';
import { ArrowRight, Building2, ChevronDown, ClipboardList, FolderTree, Search, Target, UserCheck } from 'lucide-react';
import { Badge, Empty } from '../../components/ui.jsx';
import './breakdown.css';

const pct = value => `${Number((Number(value) * 100).toFixed(2))}%`;

export default function PlanBreakdown({ activities, departmentOf, assigneeOf, onAssign, onAssignKpi }) {
  const [search, setSearch] = useState('');
  const [collapsed, setCollapsed] = useState(new Set());
  const [expandedDetails, setExpandedDetails] = useState(new Set());
  const prefix = useId();
  const detailKey = (area, kpi, row) => JSON.stringify([area.key, kpi.kpi_code, ...(row ? [row.activity_code] : [])]);
  const panelId = key => `${prefix}-${encodeURIComponent(key)}`;
  const detailOpen = key => Boolean(query) || expandedDetails.has(key);
  function toggleDetail(key) { setExpandedDetails(previous => { const next = new Set(previous); if (next.has(key)) next.delete(key); else next.add(key); return next; }); }
  const areas = useMemo(() => {
    const groups = new Map();
    for (const row of activities) {
      const key = `${row.plan_id}-${row.kpa_code}`;
      if (!groups.has(key)) groups.set(key, { key, ...row, kpis: new Map() });
      const area = groups.get(key);
      if (!area.kpis.has(row.kpi_code)) area.kpis.set(row.kpi_code, { ...row, activities: [] });
      area.kpis.get(row.kpi_code).activities.push(row);
    }
    return [...groups.values()].map(area => ({ ...area, kpis: [...area.kpis.values()] }));
  }, [activities]);
  const kpiCount = areas.reduce((total, area) => total + area.kpis.length, 0);
  const allocation = areas.reduce((total, area) => total + Number(area.kpa_weight), 0);
  const query = search.trim().toLowerCase();
  const matches = value => String(value || '').toLowerCase().includes(query);
  const visible = areas.map(area => {
    const areaMatch = [area.kpa_title, area.kpa_code, departmentOf(area.department_id)?.name].some(matches);
    return { ...area, kpis: area.kpis.map(kpi => ({ ...kpi, activities: kpi.activities.filter(row => areaMatch || [kpi.kpi_title, kpi.kpi_code, row.activity, row.activity_code].some(matches)) })).filter(kpi => kpi.activities.length) };
  }).filter(area => area.kpis.length);
  function toggle(key) { setCollapsed(previous => { const next = new Set(previous); if (next.has(key)) next.delete(key); else next.add(key); return next; }); }

  return <div className="plan-breakdown">
    <details className="plan-structure-help"><summary>About KPA, KPI &amp; activities</summary><section className="breakdown-legend" aria-label="Understand your plan structure">
      <article className="legend-kpa"><span className="breakdown-level-icon"><FolderTree size={22} /></span><div><small>01 · RESPONSIBILITY</small><h2>KPA <span>Key Performance Area</span></h2><p>The area of work you are responsible for.</p></div><ArrowRight className="legend-arrow" size={17} /></article>
      <article className="legend-kpi"><span className="breakdown-level-icon"><Target size={22} /></span><div><small>02 · MEASUREMENT</small><h2>KPI <span>Key Performance Indicator</span></h2><p>The target used to measure that area.</p></div><ArrowRight className="legend-arrow" size={17} /></article>
      <article className="legend-activity"><span className="breakdown-level-icon"><ClipboardList size={22} /></span><div><small>03 · DELIVERY</small><h2>Activity <span>Work to be done</span></h2><p>The actions needed to deliver the KPI.</p></div></article>
    </section></details>
    <div className="breakdown-totals"><div><strong>{areas.length}</strong><span>KPAs</span></div><div><strong>{kpiCount}</strong><span>KPIs</span></div><div><strong>{activities.length}</strong><span>Activities</span></div><p><strong>{pct(allocation)}</strong> of your personal plan in this scope<small>Weights show allocation, not completed performance.</small></p></div>
    <div className="breakdown-toolbar"><label className="breakdown-search"><Search size={16} /><input aria-label="Search plan breakdown" placeholder="Find a KPA, KPI or activity…" value={search} onChange={e => setSearch(e.target.value)} /></label><div><button className="text-button" disabled={Boolean(query)} onClick={() => { setCollapsed(new Set()); setExpandedDetails(new Set(areas.flatMap(area => area.kpis.flatMap(kpi => [detailKey(area, kpi), ...kpi.activities.map(row => detailKey(area, kpi, row))])))); }}>Expand all</button><span /><button className="text-button" disabled={Boolean(query)} onClick={() => { setCollapsed(new Set(areas.map(area => area.key))); setExpandedDetails(new Set()); }}>Collapse all</button></div></div>
    {!areas.length ? <section className="panel"><Empty icon={FolderTree} title="Your plan structure will appear here" text="Choose a year and department with published activities to explore its KPAs and KPIs." /></section> : !visible.length ? <section className="panel"><Empty icon={Search} title="No matching responsibilities" text="Try another KPA, KPI or activity name." action={<button className="btn btn-secondary" onClick={() => setSearch('')}>Clear search</button>} /></section> : visible.map(area => {
      const closed = collapsed.has(area.key) && !query;
      const count = area.kpis.reduce((total, kpi) => total + kpi.activities.length, 0);
      return <section className="breakdown-area breakdown-compact" key={area.key}>
        <button type="button" className="breakdown-area-heading" aria-expanded={!closed} aria-controls={panelId(area.key)} onClick={() => toggle(area.key)}>
          <span className="breakdown-level-icon"><FolderTree size={22} /></span><span className="breakdown-area-title"><span className="breakdown-type kpa-type">KPA</span><h2>{area.kpa_title}</h2><span className="breakdown-area-meta"><span><Building2 size={12} />{departmentOf(area.department_id)?.name}</span><span>{area.kpis.length} KPIs · {count} activities{query ? ' shown' : ''}</span></span></span>
          <Weight value={area.kpa_weight} label="of personal plan" tone="kpa" /><ChevronDown className={closed ? 'breakdown-chevron closed' : 'breakdown-chevron'} size={19} />
        </button>
        {!closed && <div className="breakdown-area-body" id={panelId(area.key)}>
          {area.kpis.map(kpi => {
            const key = detailKey(area, kpi), open = detailOpen(key);
            return <section className="plan-kpi-card" key={key}>
              <div className="plan-kpi-top">
                <button type="button" className="plan-node-toggle" aria-label={`${open ? 'Collapse' : 'Expand'} KPI: ${kpi.kpi_title}`} aria-expanded={open} aria-controls={panelId(key)} onClick={() => toggleDetail(key)}>
                  <span className="plan-node-copy"><span className="breakdown-type kpi-type">KPI · {kpi.activities.length} activities</span><h3>{kpi.kpi_title}</h3></span>
                  <Weight value={kpi.kpi_weight} label="within KPA" tone="kpi" /><ChevronDown size={18} className={`breakdown-chevron ${open ? '' : 'closed'}`} />
                </button>
                {onAssignKpi && <button className="btn btn-secondary btn-small plan-owner-button" onClick={() => onAssignKpi(kpi)}>{kpi.delegation ? 'Edit KPI owner' : 'Assign KPI'}<ArrowRight size={14} /></button>}
              </div>
              {open && <div className="plan-kpi-body" id={panelId(key)}>
                <details className="plan-target-details"><summary>KPI target details <span>{pct(Number(area.kpa_weight) * Number(kpi.kpi_weight))} of personal plan</span></summary>
                  <div className="breakdown-measure"><div><small>Target</small><strong>{kpi.target_value}{kpi.target_unit === 'percent' ? '%' : <span> {kpi.target_unit}</span>}</strong></div><div><small>Monitoring</small><strong>{kpi.frequency}</strong></div><div><small>Direction</small><strong>{kpi.direction === 'lower' ? 'Lower is better' : 'Higher is better'}</strong></div></div>
                </details>
                <ol className="plan-activity-items" aria-label={`Activities for ${kpi.kpi_title}`}>{kpi.activities.map((row, index) => {
                  const rowKey = detailKey(area, kpi, row), rowOpen = detailOpen(rowKey);
                  const deadline = row.assignment ? row.assignment.due_date : row.due_date;
                  return <li className="plan-activity-card" key={rowKey}>
                    <div className="plan-activity-top">
                      <button type="button" className="plan-node-toggle" aria-label={`${rowOpen ? 'Collapse' : 'Expand'} activity: ${row.activity}`} aria-expanded={rowOpen} aria-controls={panelId(rowKey)} onClick={() => toggleDetail(rowKey)}>
                        <span className="plan-node-copy"><span className="breakdown-type activity-type">Activity {String(index + 1).padStart(2, '0')}</span><h4>{row.activity}</h4><span className="plan-owner-summary"><UserCheck size={14} />{assigneeOf(row)}</span>{(row.source_changed || !row.assignee_eligible) && <Badge tone="draft">Review assignment</Badge>}</span>
                        <Weight value={row.activity_weight} label="within KPI" tone="activity" /><ChevronDown size={18} className={`breakdown-chevron ${rowOpen ? '' : 'closed'}`} />
                      </button>
                      <span className="plan-share"><strong>{pct(row.personal_weight)}</strong>of personal plan</span>
                      <button className="btn btn-secondary btn-small plan-owner-button" onClick={() => onAssign(row)}>{row.assignment ? 'Edit owner' : 'Assign owner'}<ArrowRight size={14} /></button>
                    </div>
                    {rowOpen && <div className="plan-activity-details" id={panelId(rowKey)}>
                      <div className="plan-detail-facts">{deadline && <span><small>Due date</small><strong>{deadline}</strong></span>}{row.assignment && row.delegation && <Badge>{row.assignment.ownership_mode === 'kpi' ? 'Follows KPI owner' : 'Individual override'}</Badge>}</div>
                      <div className="plan-weight-formula"><span>Personal plan share</span><strong>{pct(area.kpa_weight)} × {pct(kpi.kpi_weight)} × {pct(row.activity_weight)} = {pct(row.personal_weight)}</strong></div>
                    </div>}
                  </li>;
                })}</ol>
              </div>}
            </section>;
          })}
        </div>}
      </section>;
    })}
  </div>;
}

function Weight({ value, label, tone }) {
  const width = Math.min(100, Math.max(0, Number(value) * 100 || 0));
  return <span className={`plan-weight plan-weight-${tone}`}><strong>{pct(value)}</strong><small>{label}</small><span className="plan-weight-track" aria-hidden="true"><i style={{ width: `${width}%` }} /></span></span>;
}
