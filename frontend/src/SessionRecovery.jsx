import { useEffect, useRef, useState } from 'react';
import { ArrowRight, LoaderCircle, ShieldCheck } from 'lucide-react';
import { request } from './api.js';
import { ErrorBox, Field } from './components.jsx';
import './session-recovery.css';

export default function SessionRecovery({ user, reason, onRestored, onDiscard }) {
  const [password, setPassword] = useState(''), [busy, setBusy] = useState(false), [error, setError] = useState(null);
  const passwordRef = useRef(), panelRef = useRef();
  useEffect(() => {
    const previousFocus = document.activeElement;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden'; passwordRef.current?.focus();
    return () => { document.body.style.overflow = overflow; previousFocus?.focus(); };
  }, []);
  async function submit(e) {
    e.preventDefault(); setBusy(true); setError(null);
    try { const result = await request('/login', { method: 'POST', body: { email: user.email, password } }); await onRestored(result.user); }
    catch (err) { setError(err); setPassword(''); }
    finally { setBusy(false); }
  }
  async function discard() {
    setBusy(true); setError(null);
    try { try { await request('/logout', { method: 'POST' }); } catch (err) { if (err.status !== 401) throw err; } onDiscard(); }
    catch (err) { setError(err); }
    finally { setBusy(false); }
  }
  function keyboard(e) {
    e.stopPropagation();
    if (e.key === 'Escape') e.preventDefault();
    if (e.key === 'Tab') {
      const nodes = [...panelRef.current.querySelectorAll('input:not([disabled]), button:not([disabled])')];
      const first = nodes[0], last = nodes.at(-1);
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); }
    }
  }
  return <div className="session-recovery-backdrop" onKeyDownCapture={keyboard}><section className="session-recovery" ref={panelRef} role="dialog" aria-modal="true" aria-labelledby="session-recovery-title" aria-describedby="session-recovery-description"><span className="session-recovery-icon"><ShieldCheck size={25} /></span><div className="eyebrow">CPSTL · SESSION RECOVERY</div><h2 id="session-recovery-title">Sign in to continue</h2><p id="session-recovery-description">{reason === 'changed' ? 'Another account was used in this browser. Sign back in with the account that opened this form.' : 'Your session expired or you were signed out. Your current form entries are kept while this page stays open.'}</p><ErrorBox error={error} /><form onSubmit={submit}><Field label="Account"><input type="email" value={user.email} readOnly autoComplete="username" /></Field><Field label="Password" required><input ref={passwordRef} type="password" value={password} onChange={e => setPassword(e.target.value)} autoComplete="current-password" disabled={busy} required /></Field><button className="btn btn-primary" disabled={busy}>{busy ? <LoaderCircle className="spin" size={16} /> : <ArrowRight size={16} />}Sign in and keep my entries</button></form><p className="session-recovery-note">After signing in, click your original save or submit button again. Nothing is submitted automatically.</p><button className="text-button session-discard" disabled={busy} onClick={discard}>Sign out and discard current edits</button></section></div>;
}
