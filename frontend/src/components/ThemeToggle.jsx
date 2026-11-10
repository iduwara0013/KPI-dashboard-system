import { useSyncExternalStore } from 'react';
import { Moon, Sun } from 'lucide-react';
import { getTheme, setTheme, subscribeTheme } from '../services/theme.js';

export default function ThemeToggle() {
  const theme = useSyncExternalStore(subscribeTheme, getTheme);
  const dark = theme === 'dark';
  return <button className="theme-toggle" type="button" aria-label={`Switch to ${dark ? 'light' : 'dark'} mode`} title={`Switch to ${dark ? 'light' : 'dark'} mode`} onClick={() => setTheme(dark ? 'light' : 'dark')}>
    <span className="theme-toggle-symbol">{dark ? <Sun size={17} aria-hidden="true" /> : <Moon size={17} aria-hidden="true" />}</span>
    <span>{dark ? 'Light mode' : 'Dark mode'}</span>
  </button>;
}
