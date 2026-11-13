import { useLayoutEffect, useRef, useState } from 'react';
import { ArrowUpRight, Building2, Crown, Network, Users } from 'lucide-react';
import { Avatar } from '../../components/ui.jsx';
import './overall-reporting.css';

const leadership = [
  { name: 'Mr. D J A S De S. Rajakaruna', title: 'Chairman', className: 'company-chairman', Icon: Crown },
  { name: 'Dr. Mayura Neththikumarage', title: 'Managing Director', className: 'company-md', Icon: Network },
];

export default function OverallReporting({ data, departments, onSelectDepartment }) {
  const canvas = useRef(null);
  const [paths, setPaths] = useState([]);
  useLayoutEffect(() => {
    const element = canvas.current;
    function measure() {
      const origin = element.getBoundingClientRect();
      const rect = node => {
        const box = node.getBoundingClientRect();
        return { x: box.left - origin.left + box.width / 2, top: box.top - origin.top, bottom: box.bottom - origin.top };
      };
      const chairman = rect(element.querySelector('.company-chairman'));
      const md = rect(element.querySelector('.company-md'));
      const cards = [...element.querySelectorAll('.company-department')].map(rect);
      const next = [`M ${chairman.x} ${chairman.bottom} V ${md.top}`];
      if (cards.length) {
        const branch = Math.min(...cards.map(card => card.top)) - 22;
        // A side rail keeps links out of department cards when the grid wraps.
        next.push(`M ${md.x} ${md.bottom} V ${branch} H 20 V ${Math.max(...cards.map(card => card.top)) - 22}`);
        for (const card of cards) next.push(`M 20 ${card.top - 22} H ${card.x} V ${card.top}`);
      }
      setPaths(previous => JSON.stringify(previous) === JSON.stringify(next) ? previous : next);
    }
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(element);
    for (const card of element.querySelectorAll('.company-leader, .company-department')) observer.observe(card);
    return () => observer.disconnect();
  }, [departments, data.users, data.memberships]);

  return <div className="company-overview">
    <div className="company-map-caption"><Network size={17} /><strong>Company structure</strong><span>Select a department to manage reporting</span></div>
    <div className="company-map" ref={canvas} aria-label="Company hierarchy: Chairman, Managing Director and departments">
      <svg className="company-connectors" aria-hidden="true">{paths.map((path, index) => <path key={index} d={path} />)}</svg>
      <div className="company-leadership">{leadership.map(({ name, title, className, Icon }) => <article key={title} className={`company-leader ${className}`}>
        <span className="company-leader-role"><Icon size={17} />{title}</span>
        <div className="company-leader-person"><Avatar name={name.replace(/^(Mr\.|Dr\.)\s+/, '')} /><strong>{name}</strong></div>
      </article>)}</div>
      <div className="company-departments">{departments.map(department => {
        const memberships = data.memberships.filter(member => String(member.department_id) === String(department.id));
        const members = memberships.map(member => ({ ...member, user: data.users.find(person => String(person.id) === String(member.user_id)) }))
          .filter(member => member.user?.is_active && member.user.system_role !== 'md');
        const heads = members.filter(member => member.responsibility === 'hof' && member.user.system_role === 'member');
        return <button type="button" key={department.id} className="company-department" onClick={() => onSelectDepartment(String(department.id))} aria-label={`Open ${department.name} reporting`}>
          <span className="company-department-top"><span className="company-department-icon"><Building2 size={21} /></span><span>{department.code}</span><ArrowUpRight size={18} /></span>
          <strong>{department.name}</strong>
          <span className="company-department-head">
            <span className="company-hof-label">Head of function</span>
            {heads.length ? heads.map(member => <span className="company-hof-person" key={member.user.id}>
              <Avatar name={member.user.name} small />
              <span><strong>{member.user.name}</strong><small>{member.responsibility_title || member.user.designation || 'HOF'}</small></span>
            </span>) : <span className="company-hof-empty">Not assigned</span>}
          </span>
          <span className="company-department-footer"><span><Users size={15} />{members.length} people</span><span>View reporting →</span></span>
        </button>;
      })}</div>
      {!departments.length && <p className="company-no-departments">Add an active department from Departments to include it here.</p>}
    </div>
  </div>;
}
