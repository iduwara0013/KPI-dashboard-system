export function notificationPageSize(loaded) {
  return Math.min(300, Math.max(30, Number(loaded) || 0));
}

export function appendNotificationPage(previous, incoming) {
  const merged = new Map(previous.map(item => [String(item.id), item]));
  for (const item of incoming) merged.set(String(item.id), item);
  return [...merged.values()].sort((a, b) => Number(b.id) - Number(a.id));
}
