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

// Intl devolve "terça-feira, 27 de outubro": só a primeira letra vai em
// maiúscula ("Terça-feira, 27 de outubro"), como em português.
function formatToday() {
  const text = dateFormatter.format(new Date());

  return text.charAt(0).toUpperCase() + text.slice(1);
}

export default function Header({ isNavOpen = false, navId, onToggleNav }) {
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
      <button
        type="button"
        className={styles.menuButton}
        onClick={onToggleNav}
        aria-label={isNavOpen ? 'Fechar menu' : 'Abrir menu'}
        aria-expanded={isNavOpen}
        aria-controls={navId}
      >
        <svg viewBox="0 0 24 24" width={20} height={20} fill="none" stroke="currentColor" strokeWidth={1.9} strokeLinecap="round" aria-hidden="true">
          {isNavOpen ? <path d="M6 6l12 12M18 6 6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
        </svg>
      </button>

      <div className={styles.greetingBlock}>
        <p className={styles.greeting}>{firstName ? `Olá, ${firstName}` : 'Olá'}</p>
        <p className={styles.date}>{formatToday()}</p>
      </div>

      <div className={styles.actions}>
        <ThemeToggle />
        <UserMenu user={user} onOpenProfile={() => setIsProfileOpen(true)} onLogout={handleLogout} />
      </div>

      <ProfileModal isOpen={isProfileOpen} onClose={() => setIsProfileOpen(false)} />
    </header>
  );
}
