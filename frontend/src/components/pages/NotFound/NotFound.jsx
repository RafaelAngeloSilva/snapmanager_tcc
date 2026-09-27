import { Link } from 'react-router-dom';
import Button from '../../atoms/Button';
import styles from './NotFound.module.css';

export default function NotFound() {
  return (
    <div className={styles.page}>
      <div className={styles.card}>
        <p className={styles.code}>404</p>
        <h1 className={styles.title}>Página não encontrada</h1>
        <Link to="/">
          <Button variant="primary">Voltar para o dashboard</Button>
        </Link>
      </div>
    </div>
  );
}
