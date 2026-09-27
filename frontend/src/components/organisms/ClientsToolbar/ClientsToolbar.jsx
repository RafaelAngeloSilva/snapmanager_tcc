import Input from '../../atoms/Input';
import Button from '../../atoms/Button';
import { SearchIcon, PlusIcon } from '../../atoms/Icon';
import styles from './ClientsToolbar.module.css';

export default function ClientsToolbar({ searchTerm, onSearchChange, onCreateClick }) {
  return (
    <div className={styles.toolbar}>
      <div className={styles.searchWrap}>
        <span className={styles.searchIcon}>
          <SearchIcon />
        </span>
        <Input
          value={searchTerm}
          onChange={(event) => onSearchChange(event.target.value)}
          placeholder="Buscar por nome, e-mail, telefone ou documento..."
          className={styles.searchInput}
          aria-label="Buscar clientes"
        />
      </div>

      <Button onClick={onCreateClick}>
        <PlusIcon />
        Novo cliente
      </Button>
    </div>
  );
}
