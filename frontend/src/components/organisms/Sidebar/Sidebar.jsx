import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import { roleLabel } from '../../../utils/permissions';
import Button from '../../atoms/Button';
import Avatar from '../../atoms/Avatar';
import SidebarLink from '../../molecules/SidebarLink';
import {
  AgendaIcon,
  ClientsIcon,
  DashboardIcon,
  DocumentsIcon,
  GalleryIcon,
  LogoutIcon,
  OrdersIcon,
  ProductionIcon,
} from './icons';
import logo from '../../../assets/logo-lockup-light.png';
import styles from './Sidebar.module.css';

const NAV_LINKS = [
  { to: '/', label: 'Dashboard', end: true, icon: <DashboardIcon />, permission: 'dashboard.view' },
  { to: '/clientes', label: 'Clientes', icon: <ClientsIcon />, permission: 'clients.read' },
  { to: '/agenda', label: 'Agenda', icon: <AgendaIcon />, permission: 'appointments.read' },
  { to: '/pedidos', label: 'Pedidos', icon: <OrdersIcon />, permission: 'orders.read' },
  { to: '/producao', label: 'Produção', icon: <ProductionIcon />, permission: 'kanban.read' },
  { to: '/galerias', label: 'Galerias', icon: <GalleryIcon />, permission: 'galleries.read' },
  { to: '/documentos', label: 'Documentos', icon: <DocumentsIcon />, permission: 'documents.read' },
];

export default function Sidebar() {
  const { user, logout, can } = useAuth();
  const navigate = useNavigate();
  const visibleLinks = NAV_LINKS.filter((link) => can(link.permission));

  async function handleLogout() {
    await logout();
    navigate('/login', { replace: true });
  }

  return (
    <aside className={styles.sidebar}>
      <div className={styles.brand}>
        <img src={logo} alt="SnapManager" className={styles.brandLogo} />
      </div>

      <nav className={styles.nav}>
        {visibleLinks.map(({ permission, ...link }) => (
          <SidebarLink key={link.to} {...link} />
        ))}
      </nav>

      <div className={styles.footer}>
        {user && (
          <div className={styles.user}>
            <Avatar name={user.name} photoUrl={user.profile_photo_url} variant="onDark" size="sm" />
            <span className={styles.userInfo}>
              <span className={styles.userName}>{user.name}</span>
              <span className={styles.userRole}>{roleLabel(user.role)}</span>
            </span>
          </div>
        )}
        <Button variant="onDark" fullWidth className={styles.logoutButton} onClick={handleLogout}>
          <LogoutIcon />
          Sair
        </Button>
      </div>
    </aside>
  );
}
