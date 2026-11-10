import { useCallback, useEffect, useRef, useState } from 'react';
import { hasNavigationGuards, requestNavigation } from '../services/navigationGuard.js';

export default function useWorkspaceHistory() {
  const [pathname, setPathname] = useState(window.location.pathname);
  const [historyVisit, setHistoryVisit] = useState(0);
  const current = useRef(Number(window.history.state?.cpstlIndex) || 0);
  const returning = useRef(null), accepted = useRef(null);
  useEffect(() => {
    window.history.replaceState({ ...window.history.state, cpstlIndex: current.current }, '', window.location.href);
    const apply = (index, path) => { current.current = index; setPathname(path); setHistoryVisit(value => value + 1); };
    function followHistory(event) {
      const index = Number(event.state?.cpstlIndex) || 0;
      const path = window.location.pathname;
      if (returning.current && index === current.current) {
        const pending = returning.current; returning.current = null;
        requestNavigation(() => { accepted.current = pending.index; window.history.go(pending.index - current.current); });
        return;
      }
      if (accepted.current === index) { accepted.current = null; apply(index, path); return; }
      if (hasNavigationGuards() && index !== current.current) {
        returning.current = { index, path };
        window.history.go(current.current - index);
        return;
      }
      apply(index, path);
    }
    window.addEventListener('popstate', followHistory);
    return () => window.removeEventListener('popstate', followHistory);
  }, []);
  const navigate = useCallback((path, replace = false) => {
    if (window.location.pathname === path) return;
    const index = replace ? current.current : current.current + 1;
    window.history[replace ? 'replaceState' : 'pushState']({ cpstlIndex: index }, '', path);
    current.current = index;
    setPathname(path);
  }, []);
  return { pathname, historyVisit, navigate };
}
