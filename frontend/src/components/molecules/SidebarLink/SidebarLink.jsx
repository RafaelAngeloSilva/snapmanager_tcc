import { NavLink } from 'react-router-dom';
import styles from './SidebarLink.module.css';

export default function SidebarLink({ to, label, icon, end = false }) {
  return (
    <NavLink
      to={to}
      end={end}
      className={({ isActive }) => `${styles.link} ${isActive ? styles.active : ''}`}
    >
      {icon && <span className={styles.icon}>{icon}</span>}
      <span>{label}</span>
    </NavLink>
  );
}
