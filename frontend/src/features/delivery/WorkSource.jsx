import './work-structure.css';

export default function WorkSource({ row, activity = true }) {
  const extra = Object.hasOwn(row, 'used_for_replacement');
  if (extra || (!row.kpa_title && !row.kpi_title)) return activity && row.activity ? <div className="work-source"><div><small>{extra ? 'Extra work' : 'Parent work'}</small><strong>{row.activity}</strong></div></div> : null;
  return <div className="work-source" aria-label="Source plan structure">
    {row.kpa_title && <div><small>Source KPA</small><strong>{row.kpa_title}</strong></div>}
    {row.kpi_title && <div><small>Source KPI</small><strong>{row.kpi_title}</strong></div>}
    {activity && row.activity && <div><small>Activity</small><strong>{row.activity}</strong></div>}
  </div>;
}
