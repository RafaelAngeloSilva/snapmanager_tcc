import { useEffect, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import Sidebar from '../../organisms/Sidebar';
import Header from '../../organisms/Header';
import styles from './AdminTemplate.module.css';

/**
 * Em telas largas a sidebar fica fixa ao lado do conteúdo. Até 900px ela
 * vira uma gaveta (drawer) aberta pelo botão de menu do Header — antes ela
 * ocupava 258px fixos e espremia todas as telas no celular.
 */
export default function AdminTemplate() {
  const [isNavOpen, setIsNavOpen] = useState(false);
  const location = useLocation();

  // Fecha a gaveta ao navegar.
  useEffect(() => {
    setIsNavOpen(false);
  }, [location.pathname]);

  useEffect(() => {
    if (!isNavOpen) return undefined;

    function handleKeyDown(event) {
      if (event.key === 'Escape') setIsNavOpen(false);
    }

    document.addEventListener('keydown', handleKeyDown);

    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isNavOpen]);

  return (
    <div className={styles.layout}>
      <Sidebar isOpen={isNavOpen} id="admin-navigation" />

      {isNavOpen && <div className={styles.backdrop} onClick={() => setIsNavOpen(false)} aria-hidden="true" />}

      <div className={styles.main}>
        <Header isNavOpen={isNavOpen} navId="admin-navigation" onToggleNav={() => setIsNavOpen((open) => !open)} />
        <main className={styles.content}>
          <Outlet />
        </main>
      </div>
    </div>
  );
}
