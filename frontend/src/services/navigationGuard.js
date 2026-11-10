const guards = new Map();

export function registerNavigationGuard(handler) {
  const key = Symbol('unsaved-work');
  guards.set(key, handler);
  return () => guards.delete(key);
}

export function hasNavigationGuards() {
  return guards.size > 0;
}

export function requestNavigation(action) {
  const pending = [...guards.entries()];
  function next(index) {
    if (index === pending.length) { action(); return; }
    const [key, handler] = pending[index];
    if (!guards.has(key)) { next(index + 1); return; }
    handler(() => next(index + 1));
  }
  next(0);
}
