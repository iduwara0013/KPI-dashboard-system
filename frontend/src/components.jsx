import { useEffect, useRef } from 'react';
import { Users, X } from 'lucide-react';

const initials = (name = '') => name.split(' ').filter(Boolean).slice(0, 2).map(x => x[0]).join('').toUpperCase();

export function Avatar({ name, index = 0, small = false }) {
  return <span className={`avatar ${small ? 'avatar-small' : ''} tone-${index % 5}`}>{initials(name)}</span>;
}
export function Brand() {
  return <><img className="brand-logo" src="/cpstl-logo.png" alt="CPSTL logo" width="58" height="58" /><span className="brand-copy"><strong>CPSTL</strong><small>Performance Management</small></span></>;
}
export function Badge({ children, tone = 'neutral' }) { return <span className={`badge badge-${tone}`}>{children}</span>; }
export function Empty({ icon: Icon = Users, title, text, action }) {
  return <div className="empty"><Icon size={30} /><h3>{title}</h3><p>{text}</p>{action}</div>;
}
export function ErrorBox({ error }) {
  if (!error) return null;
  const messages = error.errors ? Object.values(error.errors).flat() : [];
  return <div className="error-box" role="alert">{messages.length ? <ul>{messages.map((m, i) => <li key={i}>{m}</li>)}</ul> : error.message || String(error)}</div>;
}
export function Field({ label, children, hint, required }) {
  return <label className="field"><span>{label}{required && <span className="required"> *</span>}</span>{children}{hint && <small>{hint}</small>}</label>;
}

export function Modal({ title, subtitle, onClose, children }) {
  const dialogRef = useRef();
  useEffect(() => {
    const previous = document.activeElement;
    document.body.style.overflow = 'hidden';
    const focusables = () => [...dialogRef.current.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]')].filter(x => x.getClientRects().length);
    focusables()[0]?.focus();
    function keydown(e) {
      if (e.key === 'Escape') onClose();
      if (e.key === 'Tab') { const nodes = focusables(); const first = nodes[0], last = nodes.at(-1); if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); } }
    }
    document.addEventListener('keydown', keydown);
    return () => { document.body.style.overflow = ''; document.removeEventListener('keydown', keydown); previous?.focus(); };
  }, []);
  return <div className="modal-backdrop" onMouseDown={e => { if (e.target === e.currentTarget) onClose(); }}><section className="modal" ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby="modal-title"><div className="modal-header"><div><h2 id="modal-title">{title}</h2><p>{subtitle}</p></div><button className="icon-button" type="button" aria-label="Close dialog" onClick={onClose}><X size={21} /></button></div>{children}</section></div>;
}

