const storageKey = 'cpstl-theme';
const changeEvent = 'cpstl-theme-change';

function savedTheme() {
  try {
    const value = window.localStorage.getItem(storageKey);
    return value === 'dark' || value === 'light' ? value : null;
  } catch {
    return null;
  }
}

function systemTheme() {
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme(theme) {
  document.documentElement.dataset.theme = theme;
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#0b1728' : '#c5120c');
}

export function getTheme() {
  return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
}

export function setTheme(theme) {
  if (!['light', 'dark'].includes(theme)) return;
  try { window.localStorage.setItem(storageKey, theme); } catch { /* The switch still works when browser storage is unavailable. */ }
  applyTheme(theme);
  window.dispatchEvent(new Event(changeEvent));
}

export function subscribeTheme(listener) {
  const media = window.matchMedia('(prefers-color-scheme: dark)');
  const followSystem = () => { if (!savedTheme()) { applyTheme(systemTheme()); listener(); } };
  const followStorage = event => {
    if (event.key === storageKey || event.key === null) { applyTheme(savedTheme() || systemTheme()); listener(); }
  };
  window.addEventListener(changeEvent, listener);
  window.addEventListener('storage', followStorage);
  media.addEventListener('change', followSystem);
  return () => {
    window.removeEventListener(changeEvent, listener);
    window.removeEventListener('storage', followStorage);
    media.removeEventListener('change', followSystem);
  };
}

applyTheme(savedTheme() || systemTheme());
