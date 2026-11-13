import { useState } from 'react';
import { KeyRound, LoaderCircle, LogOut, ShieldCheck } from 'lucide-react';
import { ErrorBox, Field } from '../../components/ui.jsx';
import { request } from '../../services/api.js';
import './change-password.css';

export default function ChangePassword({ session, onSession, onLogout }) {
  const [current, setCurrent] = useState(''), [password, setPassword] = useState(''), [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false), [error, setError] = useState(null);
  async function submit(event) {
    event.preventDefault(); setError(null);
    if (password !== confirmation) { setError(new Error('The new passwords do not match.')); return; }
    setBusy(true);
    try { const result = await request('/password/change', { method: 'POST', body: { current_password: current, password, password_confirmation: confirmation } }); onSession(result.user); }
    catch (err) { setError(err); } finally { setBusy(false); }
  }
  async function logout() {
    setBusy(true); setError(null);
    try { try { await request('/logout', { method: 'POST' }); } catch (err) { if (err.status !== 401) throw err; } onLogout(); }
    catch (err) { setError(err); } finally { setBusy(false); }
  }
  return <main className="password-change-page"><section className="password-change-card" aria-labelledby="password-change-title">
    <img src="/cpstl-logo.png" alt="CPSTL logo" width="58" height="58" />
    <span className="password-change-icon"><KeyRound size={23} /></span>
    <h1 id="password-change-title">Set your new password</h1>
    <p>Welcome, {session.name}. Change your initial password to open your workspace.</p>
    <div className="password-change-account"><ShieldCheck size={18} /><span>EPF number <strong>{session.employee_number}</strong></span></div>
    <ErrorBox error={error} />
    <form onSubmit={submit}>
      <Field label="Current password" required hint="For your first login, use your EPF number."><input type="password" autoComplete="current-password" maxLength={128} required disabled={busy} value={current} onChange={event => setCurrent(event.target.value)} /></Field>
      <Field label="New password" required hint="At least 6 characters. Choose a password different from your EPF number."><input type="password" autoComplete="new-password" minLength={6} maxLength={128} required disabled={busy} value={password} onChange={event => setPassword(event.target.value)} /></Field>
      <Field label="Confirm new password" required><input type="password" autoComplete="new-password" minLength={6} maxLength={128} required disabled={busy} value={confirmation} onChange={event => setConfirmation(event.target.value)} /></Field>
      <button className="btn btn-primary" disabled={busy}>{busy ? <LoaderCircle className="spin" size={17} /> : <ShieldCheck size={17} />}Save password & continue</button>
    </form>
    <button className="text-button" disabled={busy} onClick={logout}><LogOut size={16} />Sign out</button>
  </section></main>;
}
