export function planAssignmentDeadline(kind, row) {
  const activities = kind === 'kpi' ? row.activities || [] : [row];
  return activities.map(activity => activity.due_date?.slice(0, 10) || '').filter(Boolean).sort().at(-1) || '';
}

export function initialAssignmentDeadline(kind, row) {
  const record = kind === 'kpi' ? row.delegation : row.assignment;
  return record ? record.due_date?.slice(0, 10) || '' : planAssignmentDeadline(kind, row);
}
