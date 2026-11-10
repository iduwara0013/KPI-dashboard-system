import { Badge } from '../../components/ui.jsx';
import { statuses } from './deliveryFormat.js';

export function Progress({ label, value, approved = false }) { return <div className={`delivery-progress ${approved ? 'delivery-approved' : ''}`}><span>{label}<strong>{Number(Number(value || 0).toFixed(2))}%</strong></span><div><span style={{ width: `${Math.min(100, Math.max(0, Number(value || 0)))}%` }} /></div></div>; }
export function TaskBadge({ task }) { return <Badge tone={task.status === 'approved' ? 'mint' : task.status === 'needs_changes' ? 'draft' : 'neutral'}>{task.self_confirm && task.status === 'approved' ? 'Confirmed' : statuses[task.status] || task.status}</Badge>; }
