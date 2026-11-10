import { useMemo, useState } from 'react';
import { ArrowRight, Building2, ChevronDown, ClipboardList, FolderTree, Search, Target, UserCheck } from 'lucide-react';
import { Badge, Empty } from '../../components/ui.jsx';
import './breakdown.css';

const pct = value => `${Number((Number(value) * 100).toFixed(2))}%`;

export default function PlanBreakdown({ activities, departmentOf, assigneeOf, onAssign, onAssignKpi }) {
  const [search, setSearch] = useState('');
  const [collapsed, setCollapsed] = useState(new Set());
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
    <section className="breakdown-legend" aria-label="Understand your plan structure">
      <article className="legend-kpa"><span className="breakdown-level-icon"><FolderTree size={22} /></span><div><small>01 · RESPONSIBILITY</small><h2>KPA <span>Key Performance Area</span></h2><p>The area of work you are responsible for.</p></div><ArrowRight className="legend-arrow" size={17} /></article>
      <article className="legend-kpi"><span className="breakdown-level-icon"><Target size={22} /></span><div><small>02 · MEASUREMENT</small><h2>KPI <span>Key Performance Indicator</span></h2><p>The target used to measure that area.</p></div><ArrowRight className="legend-arrow" size={17} /></article>
      <article className="legend-activity"><span className="breakdown-level-icon"><ClipboardList size={22} /></span><div><small>03 · DELIVERY</small><h2>Activity <span>Work to be done</span></h2><p>The actions needed to deliver the KPI.</p></div></article>
    </section>
    <div className="breakdown-totals"><div><strong>{areas.length}</strong><span>KPAs</span></div><div><strong>{kpiCount}</strong><span>KPIs</span></div><div><strong>{activities.length}</strong><span>Activities</span></div><p><strong>{pct(allocation)}</strong> of your personal plan in this scope<small>Weights show allocation, not completed performance.</small></p></div>
    <div className="breakdown-toolbar"><label className="breakdown-search"><Search size={16} /><input aria-label="Search plan breakdown" placeholder="Find a KPA, KPI or activity…" value={search} onChange={e => setSearch(e.target.value)} /></label><div><button className="text-button" disabled={Boolean(query)} onClick={() => setCollapsed(new Set())}>Expand all</button><span /><button className="text-button" disabled={Boolean(query)} onClick={() => setCollapsed(new Set(areas.map(area => area.key)))}>Collapse all</button></div></div>
    {!areas.length ? <section className="panel"><Empty icon={FolderTree} title="Your plan structure will appear here" text="Choose a year and department with published activities to explore its KPAs and KPIs." /></section> : !visible.length ? <section className="panel"><Empty icon={Search} title="No matching responsibilities" text="Try another KPA, KPI or activity name." action={<button className="btn btn-secondary" onClick={() => setSearch('')}>Clear search</button>} /></section> : visible.map(area => {
      const closed = collapsed.has(area.key) && !query;
      const count = area.kpis.reduce((total, kpi) => total + kpi.activities.length, 0);
      return <section className="breakdown-area" key={area.key}>
        <button className="breakdown-area-heading" aria-expanded={!closed} aria-controls={`area-${area.key}`} onClick={() => toggle(area.key)}>
          <span className="breakdown-level-icon"><FolderTree size={22} /></span><span className="breakdown-area-title"><span className="breakdown-type kpa-type">KPA · KEY PERFORMANCE AREA</span><h2>{area.kpa_title}</h2><span className="breakdown-area-meta"><span><Building2 size={12} />{departmentOf(area.department_id)?.name}</span><span>{area.kpis.length} KPI{area.kpis.length === 1 ? '' : 's'} · {count} {count === 1 ? 'activity' : 'activities'}{query ? ' shown' : ''}</span></span></span>
          <span className="breakdown-area-weight"><strong>{pct(area.kpa_weight)}</strong><small>of personal plan</small></span><ChevronDown className={closed ? 'breakdown-chevron closed' : 'breakdown-chevron'} size={18} />
        </button>
        {!closed && <div className="breakdown-area-body" id={`area-${area.key}`}>
          {area.kpis.map(kpi => <section className="breakdown-kpi" key={kpi.kpi_code}>
            <div className="breakdown-kpi-heading"><span className="breakdown-level-icon"><Target size={19} /></span><div><span className="breakdown-type kpi-type">KPI · KEY PERFORMANCE INDICATOR</span><h3>{kpi.kpi_title}</h3></div><div className="breakdown-kpi-weight"><strong>{pct(kpi.kpi_weight)}</strong><small>within this KPA</small></div></div>
            {onAssignKpi && <div className="breakdown-kpi-assign"><span>{kpi.delegation ? 'Overall KPI owner assigned · activity overrides remain visible below' : 'Assign the full KPI, or assign individual activities below'}</span><button className="btn btn-secondary btn-small" onClick={() => onAssignKpi(kpi)}>{kpi.delegation ? 'Edit KPI assignment' : 'Assign KPI'}<ArrowRight size={12} /></button></div>}
            <div className="breakdown-measure"><div><small>TARGET</small><strong>{kpi.target_value}{kpi.target_unit === 'percent' ? '%' : <span> {kpi.target_unit}</span>}</strong></div><div><small>MONITORING</small><strong>{kpi.frequency}</strong></div><div><small>SUCCESS DIRECTION</small><strong>{kpi.direction === 'lower' ? 'Lower is better' : 'Higher is better'}</strong></div><div><small>PERSONAL PLAN SHARE</small><strong>{pct(Number(area.kpa_weight) * Number(kpi.kpi_weight))}</strong></div></div>
            <div className="breakdown-activity-caption"><ClipboardList size={14} /><strong>Activities</strong><span>The work that delivers this KPI</span></div>
            <ol className="breakdown-activity-list">{kpi.activities.map((row, index) => {
              const deadline = row.assignment ? row.assignment.due_date : row.due_date;
              return <li key={row.activity_code}><span className="breakdown-activity-number">{String(index + 1).padStart(2, '0')}</span><div className="breakdown-activity-content"><span className="breakdown-type activity-type">ACTIVITY</span><h4>{row.activity}</h4><div className="breakdown-activity-meta"><span><UserCheck size={12} />{assigneeOf(row)}</span>{row.assignment && row.delegation && <Badge>{row.assignment.ownership_mode === "kpi" ? "Follows KPI owner" : "Individual override"}</Badge>}{deadline && <span>Due {deadline}</span>}{(row.source_changed || !row.assignee_eligible) && <Badge tone="draft">Review assignment</Badge>}</div><div className="breakdown-weight-example"><span>{pct(area.kpa_weight)} KPA</span><b>×</b><span>{pct(kpi.kpi_weight)} KPI</span><b>×</b><span>{pct(row.activity_weight)} activity</span><b>=</b><strong>{pct(row.personal_weight)} of personal plan</strong></div></div><div className="breakdown-activity-action"><strong>{pct(row.activity_weight)}</strong><small>within this KPI</small><button className="btn btn-secondary btn-small" onClick={() => onAssign(row)}>{row.assignment ? 'Edit owner' : 'Assign owner'}<ArrowRight size={12} /></button></div></li>;
            })}</ol>
          </section>)}
        </div>}
      </section>;
    })}
  </div>;
}
