export function assignedWeights(rows) {
  const years = new Map(), areas = new Map(), kpis = new Map();
  const keys = row => {
    const area = JSON.stringify([row.year, row.plan_id, row.owner_id, row.kpa_code || row.kpa_title]);
    return [row.year, area, JSON.stringify([area, row.kpi_code || row.kpi_title])];
  };
  const weight = row => Math.max(0, Number(row.personal_weight) || 0);
  for (const row of rows) {
    const groupKeys = keys(row);
    [years, areas, kpis].forEach((group, index) => group.set(groupKeys[index], (group.get(groupKeys[index]) || 0) + weight(row)));
  }
  const ratio = (value, total) => total > 0 ? value / total : 0;
  return new Map(rows.map(row => {
    const [year, area, kpi] = keys(row);
    return [row, {
      kpa: ratio(areas.get(area), years.get(year)),
      kpi: ratio(kpis.get(kpi), areas.get(area)),
      activity: ratio(weight(row), kpis.get(kpi)),
      overall: ratio(weight(row), years.get(year)),
    }];
  }));
}
