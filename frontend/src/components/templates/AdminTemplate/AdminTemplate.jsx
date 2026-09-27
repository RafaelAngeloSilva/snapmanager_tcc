import { Outlet } from 'react-router-dom';
import Sidebar from '../../organisms/Sidebar';
import Header from '../../organisms/Header';
import styles from './AdminTemplate.module.css';

export default function AdminTemplate() {
  return (
    <div className={styles.layout}>
      <Sidebar />

      <div className={styles.main}>
        <Header />
        <main className={styles.content}>
          <Outlet />
        </main>
      </div>
    </div>
  );
}
