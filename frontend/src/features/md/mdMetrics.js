export function deliveryStats(rows) {
  const weight = rows.reduce((s, r) => s + Number(r.weight), 0);
  const points = rows.reduce((s, r) => s + Number(r.weight) * r.approved, 0);
  return { completion: weight ? points / weight : null, points, pending: rows.reduce((s, r) => s + r.pending, 0), overdue: rows.filter(r => r.overdue).length };
}
