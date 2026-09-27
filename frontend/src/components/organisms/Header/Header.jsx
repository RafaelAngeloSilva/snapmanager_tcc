import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import ThemeToggle from '../../atoms/ThemeToggle';
import UserMenu from '../../molecules/UserMenu';
import ProfileModal from '../ProfileModal';
import styles from './Header.module.css';

const dateFormatter = new Intl.DateTimeFormat('pt-BR', {
  weekday: 'long',
  day: '2-digit',
  month: 'long',
});

export default function Header() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [isProfileOpen, setIsProfileOpen] = useState(false);
  const firstName = user?.name?.split(' ')[0];

  async function handleLogout() {
    await logout();
    navigate('/login', { replace: true });
  }

  return (
    <header className={styles.header}>
      <div>
        <p className={styles.greeting}>{firstName ? `Olá, ${firstName}` : 'Olá'}</p>
        <p className={styles.date}>{dateFormatter.format(new Date())}</p>
      </div>

      <div className={styles.actions}>
        <ThemeToggle />
        <UserMenu user={user} onOpenProfile={() => setIsProfileOpen(true)} onLogout={handleLogout} />
      </div>

      <ProfileModal isOpen={isProfileOpen} onClose={() => setIsProfileOpen(false)} />
    </header>
  );
}
