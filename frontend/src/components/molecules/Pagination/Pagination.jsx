import Button from '../../atoms/Button';
import styles from './Pagination.module.css';

export default function Pagination({ currentPage, lastPage, onPageChange }) {
  if (!lastPage || lastPage <= 1) {
    return null;
  }

  return (
    <div className={styles.pagination}>
      <Button variant="secondary" onClick={() => onPageChange(currentPage - 1)} disabled={currentPage <= 1}>
        Anterior
      </Button>
      <span className={styles.info}>
        Página {currentPage} de {lastPage}
      </span>
      <Button variant="secondary" onClick={() => onPageChange(currentPage + 1)} disabled={currentPage >= lastPage}>
        Próxima
      </Button>
    </div>
  );
}
