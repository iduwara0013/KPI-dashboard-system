import { useState } from 'react';
import { ArrowRight, ArrowUpRight, Check, Eye, EyeOff, KeyRound, LoaderCircle, LockKeyhole, Mail, ShieldCheck } from 'lucide-react';
import { request } from '../../services/api.js';
import { ErrorBox } from '../../components/ui.jsx';
import './login.css';

function TerminalArtwork() {
  return <svg className="access-terminal" viewBox="0 0 640 250" fill="none" aria-hidden="true">
    <path d="M0 230H640M25 246H615M42 217V126M48 126H294M54 126V217M284 126V217" />
    <path d="M66 217V119C66 108 111 99 166 99S266 108 266 119V217M66 119C66 130 111 139 166 139S266 130 266 119M66 211C66 222 111 231 166 231S266 222 266 211" />
    <path d="M78 110V96C78 86 117 78 166 78S254 86 254 96V110M117 82V72H214V82M154 71V56H180V71M83 141V204M89 141V204M82 152H90M82 167H90M82 182H90M82 197H90" />
    <path d="M301 217V150C301 141 335 134 376 134S451 141 451 150V217M301 150C301 159 335 166 376 166S451 159 451 150M301 212C301 221 335 228 376 228S451 221 451 212M311 144V132C311 124 340 118 376 118S441 124 441 132V144M365 117V104H387V117" />
    <path d="M482 224V66H494V224M488 67V41H551V63M526 63H576V79H526V63ZM529 85H573M529 94H573M543 79V224M556 79V224M543 118H556M543 158H556M543 198H556" />
    <path d="M166 228V240H376V228M451 192H469V222H604M575 222V177H613M595 177V135M602 177V135M588 135H610M598 135V119" />
    <path className="access-terminal-accent" d="M34 218H110M284 230H344M469 222H518M488 41H551V62" />
    <circle cx="207" cy="239" r="5" /><circle cx="468" cy="206" r="5" /><path d="M203 235L211 243M464 202L472 210" />
  </svg>;
}

export default function Login({ onLogin }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [visible, setVisible] = useState(false);
  const [capsLock, setCapsLock] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function submit(event) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      const result = await request('/login', { method: 'POST', body: { email: email.trim(), password } });
      onLogin(result.user);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return <div className="access-page">
    <header className="access-header">
      <div className="access-brand">
        <img src="/cpstl-logo.png" alt="CPSTL logo" width="54" height="54" />
        <div><strong>CPSTL<span> / </span></strong><small>Performance Management</small></div>
      </div>
      <div className="access-header-note"><span /> One organization. Shared ambition.</div>
    </header>

    <main className="access-layout">
      <section className="access-story" aria-labelledby="access-story-heading">
        <div className="access-story-top"><span className="access-overline">OUR PEOPLE. OUR PROGRESS.</span><ArrowUpRight size={21} aria-hidden="true" /></div>
        <div className="access-story-copy">
          <div className="access-story-rule" />
          <h1 id="access-story-heading">Great work.<br />{' '}Stronger teams.<br /><span>One direction.</span></h1>
          <p>Bringing people, responsibilities and performance together. Move forward with a clear view of what matters.</p>
        </div>
        <div className="access-journey" aria-label="Performance workflow">
          {['Plan with purpose', 'Deliver together', 'See your impact'].map((label, index) => <div key={label}>
            <span className="access-journey-number">0{index + 1}</span><span>{label}</span>{index < 2 && <ArrowRight size={14} aria-hidden="true" />}
          </div>)}
        </div>
        <div className="access-story-bottom"><TerminalArtwork /><span>Ceylon Petroleum Storage<br />Terminals Limited</span><span className="access-story-location">SRI LANKA <span>↗</span></span></div>
      </section>

      <section className="access-panel" aria-labelledby="access-form-heading">
        <div className="access-form-wrap">
          <div className="access-account-label"><span><KeyRound size={16} aria-hidden="true" /></span> YOUR CPSTL WORKSPACE</div>
          <h2 id="access-form-heading">Welcome back<span>.</span></h2>
          <p className="access-intro">Your next step starts here.<br />Sign in to stay connected to your work and team.</p>
          <ErrorBox error={error} />
          <form className="access-form" onSubmit={submit} aria-busy={busy}>
            <div className="access-field">
              <label htmlFor="access-email">Email address <span>*</span></label>
              <div className="access-input"><Mail size={19} aria-hidden="true" /><input id="access-email" name="email" type="email" autoComplete="username" placeholder="you@cpstl.lk" value={email} onChange={event => setEmail(event.target.value)} required disabled={busy} spellCheck={false} autoCapitalize="none" /></div>
            </div>
            <div className="access-field">
              <label htmlFor="access-password">Password <span>*</span></label>
              <div className="access-input"><LockKeyhole size={19} aria-hidden="true" /><input id="access-password" name="password" type={visible ? 'text' : 'password'} autoComplete="current-password" placeholder="Enter your password" value={password} onChange={event => setPassword(event.target.value)} onKeyDown={event => setCapsLock(event.getModifierState('CapsLock'))} onKeyUp={event => setCapsLock(event.getModifierState('CapsLock'))} onBlur={() => setCapsLock(false)} required disabled={busy} />
                <button className="access-password-toggle" type="button" aria-label={visible ? 'Hide password' : 'Show password'} aria-pressed={visible} onClick={() => setVisible(value => !value)} disabled={busy}>{visible ? <EyeOff size={19} /> : <Eye size={19} />}</button>
              </div>
              {capsLock && <small className="access-caps" role="status">Caps Lock is on</small>}
            </div>
            <button className="access-submit" type="submit" disabled={busy}><span>{busy ? 'Signing in…' : 'Sign in'}</span>{busy ? <LoaderCircle className="spin" size={20} aria-hidden="true" /> : <ArrowRight size={20} aria-hidden="true" />}</button>
          </form>
          <div className="access-role-note"><ShieldCheck size={21} aria-hidden="true" /><div><strong>A workspace that fits your role</strong><p>Your account connects you to the right people, plans and responsibilities.</p></div></div>
          <div className="access-help"><span>Need an account or help signing in?</span><strong>Contact your system administrator.</strong></div>
        </div>
        <div className="access-panel-footer"><span><Check size={13} aria-hidden="true" /> For authorized CPSTL personnel</span><span>EMPLOYEE PORTAL</span></div>
      </section>
    </main>
    <footer className="access-footer"><span>© {new Date().getFullYear()} CPSTL. All rights reserved.</span><span>People connected. Performance in focus.</span></footer>
  </div>;
}
