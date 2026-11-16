export const cardWidth = 240;
const gap = 36;
const same = (a, b) => String(a) === String(b);
const limit = (value, maximum) => Math.min(maximum, Math.max(16, Number(value)));

export function safePosition(point) {
  return point && Number.isFinite(point.x) && Number.isFinite(point.y)
    ? { x: limit(point.x, 20000), y: limit(point.y, 200000) } : null;
}

export function reportingLayout(people, links, collapsed, canvasWidth, heights = {}, manual = {}, showUnassigned = false) {
  const ids = new Set(people.map(person => String(person.id)));
  const edges = links.filter(link => ids.has(String(link.manager_id)) && ids.has(String(link.user_id)));
  const roots = people.filter(person => !edges.some(link => same(link.user_id, person.id)));
  const visible = new Set(roots.map(person => String(person.id)));
  const queue = [...visible];
  for (let index = 0; index < queue.length; index++) {
    if (collapsed.has(queue[index])) continue;
    for (const edge of edges.filter(link => same(link.manager_id, queue[index]))) {
      const child = String(edge.user_id);
      if (!visible.has(child)) { visible.add(child); queue.push(child); }
    }
  }
  const levels = Object.fromEntries(people.map(person => [person.id, 0]));
  for (let index = 0; index < people.length; index++) {
    let changed = false;
    for (const edge of edges) {
      if (levels[edge.user_id] <= levels[edge.manager_id]) {
        levels[edge.user_id] = Math.min(people.length, levels[edge.manager_id] + 1); changed = true;
      }
    }
    if (!changed) break;
  }
  const sorted = people.filter(person => visible.has(String(person.id))).sort((a, b) => a.name.localeCompare(b.name));
  const linked = new Set(edges.flatMap(edge => [String(edge.manager_id), String(edge.user_id)]));
  const connected = sorted.filter(person => !showUnassigned || linked.has(String(person.id)));
  const loose = sorted.filter(person => showUnassigned && !linked.has(String(person.id)));
  const primary = new Map();
  for (const person of connected) {
    const parents = connected.filter(parent => edges.some(edge => same(edge.user_id, person.id) && same(edge.manager_id, parent.id)))
      .filter(parent => levels[parent.id] < levels[person.id]).sort((a, b) => levels[b.id] - levels[a.id] || a.name.localeCompare(b.name));
    if (parents.length) primary.set(String(person.id), String(parents[0].id));
  }
  const children = person => connected.filter(child => primary.get(String(child.id)) === String(person.id));
  const nodeHeight = id => Math.max(104, Number(heights[id]) || 160);
  // Reserve a horizontal band for each subtree and a measured row per level.
  const levelTops = [30];
  const maxLevel = Math.max(0, ...connected.map(person => levels[person.id]));
  for (let level = 0; level < maxLevel; level++) {
    const row = connected.filter(person => levels[person.id] === level);
    levelTops[level + 1] = levelTops[level] + Math.max(104, ...row.map(person => nodeHeight(person.id))) + 80;
  }
  const spans = {};
  function span(person) {
    if (spans[person.id] !== undefined) return spans[person.id];
    const descendants = children(person);
    return spans[person.id] = Math.max(cardWidth, descendants.reduce((sum, child) => sum + span(child), 0) + Math.max(0, descendants.length - 1) * gap);
  }
  const positions = {};
  function place(person, left) {
    positions[person.id] = { x: left + (span(person) - cardWidth) / 2, y: levelTops[levels[person.id]] };
    const descendants = children(person);
    const total = descendants.reduce((sum, child) => sum + span(child), 0) + Math.max(0, descendants.length - 1) * gap;
    let cursor = left + (span(person) - total) / 2;
    for (const child of descendants) { place(child, cursor); cursor += span(child) + gap; }
  }
  const forest = connected.filter(person => !primary.has(String(person.id)));
  const forestWidth = forest.reduce((sum, root) => sum + span(root), 0) + Math.max(0, forest.length - 1) * gap;
  let left = Math.max(30, (canvasWidth - forestWidth) / 2);
  for (const root of forest) { place(root, left); left += span(root) + gap; }
  let top = Math.max(30, ...connected.map(person => positions[person.id].y + nodeHeight(person.id) + 70));
  const columns = Math.max(1, Math.floor((canvasWidth - 60 + gap) / (cardWidth + gap)));
  for (let index = 0; index < loose.length; index += columns) {
    const row = loose.slice(index, index + columns);
    for (const [column, person] of row.entries()) positions[person.id] = { x: 30 + column * (cardWidth + gap), y: top };
    top += Math.max(...row.map(person => nodeHeight(person.id))) + gap;
  }
  for (const id of Object.keys(positions)) {
    const saved = safePosition(manual[id]);
    if (saved) positions[id] = saved;
  }
  return { positions, width: Math.max(560, ...Object.values(positions).map(point => point.x + cardWidth + 30)),
    height: Math.max(200, ...Object.entries(positions).map(([id, point]) => point.y + nodeHeight(id) + 30)) };
}

export function reportingConnector(start, end, startHeight = 160, endHeight = 160) {
  const sx = start.x + cardWidth / 2, ex = end.x + cardWidth / 2;
  if (end.y >= start.y + startHeight + 20) {
    const sy = start.y + startHeight, ey = end.y, middle = sy + 30;
    // Skip-level links use the outside rail rather than crossing intervening cards.
    if (ey - sy > 300) return `M ${sx} ${sy} V ${middle} H 8 V ${ey - 26} H ${ex} V ${ey}`;
    return `M ${sx} ${sy} V ${middle} H ${ex} V ${ey}`;
  }
  if (start.y >= end.y + endHeight + 20) {
    const sy = start.y, ey = end.y + endHeight, middle = sy - 30;
    return `M ${sx} ${sy} V ${middle} H ${ex} V ${ey}`;
  }
  // Manual dragging can put cards alongside each other.
  const rightward = ex >= sx;
  const fromX = rightward ? start.x + cardWidth : start.x;
  const toX = rightward ? end.x : end.x + cardWidth;
  const sy = start.y + startHeight / 2, ey = end.y + endHeight / 2, middle = (fromX + toX) / 2;
  return `M ${fromX} ${sy} H ${middle} V ${ey} H ${toX}`;
}
