import { useEffect, useRef, useState } from 'react';
import Avatar from '../../atoms/Avatar';
import { LogoutIcon, UserIcon } from '../../atoms/Icon';
import { roleLabel } from '../../../utils/permissions';
import styles from './UserMenu.module.css';

export default function UserMenu({ user, onOpenProfile, onLogout }) {
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef(null);

  useEffect(() => {
    if (!isOpen) return undefined;

    function handleClickOutside(event) {
      if (containerRef.current && !containerRef.current.contains(event.target)) {
        setIsOpen(false);
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        setIsOpen(false);
      }
    }

    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  function handleProfileClick() {
    setIsOpen(false);
    onOpenProfile();
  }

  function handleLogoutClick() {
    setIsOpen(false);
    onLogout();
  }

  return (
    <div className={styles.wrapper} ref={containerRef}>
      <button
        type="button"
        className={styles.trigger}
        onClick={() => setIsOpen((current) => !current)}
        aria-haspopup="true"
        aria-expanded={isOpen}
        aria-label="Menu do usuário"
      >
        <Avatar name={user?.name} photoUrl={user?.profile_photo_url} size="md" />
      </button>

      {isOpen && (
        <div className={styles.menu} role="menu">
          <div className={styles.menuHeader}>
            <Avatar name={user?.name} photoUrl={user?.profile_photo_url} size="md" />
            <div className={styles.menuUserInfo}>
              <p className={styles.menuName}>{user?.name}</p>
              <p className={styles.menuRole}>{roleLabel(user?.role)}</p>
            </div>
          </div>

          <div className={styles.divider} />

          <button type="button" className={styles.menuItem} role="menuitem" onClick={handleProfileClick}>
            <UserIcon width={16} height={16} />
            Meu perfil
          </button>
          <button type="button" className={styles.menuItem} role="menuitem" onClick={handleLogoutClick}>
            <LogoutIcon width={16} height={16} />
            Sair
          </button>
        </div>
      )}
    </div>
  );
}
