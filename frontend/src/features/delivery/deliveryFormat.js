export const same = (a, b) => String(a) === String(b);
export const dateLabel = value => value ? new Date(value.includes('Z') || value.includes('+') ? value : value.replace(' ', 'T') + 'Z').toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
export const pct = value => `${Number((Number(value) * 100).toFixed(2))}%`;
export const statuses = { assigned: 'Assigned', in_progress: 'In progress', submitted: 'Awaiting review', needs_changes: 'Needs changes', approved: 'Approved', superseded: 'Superseded' };
export const editable = task => !task.has_children && task.status !== 'approved' && (task.status !== 'submitted' || task.self_confirm);
