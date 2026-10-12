import Input from '../../atoms/Input';
import Button from '../../atoms/Button';
import { SearchIcon, PlusIcon } from '../../atoms/Icon';
import styles from './ClientsToolbar.module.css';
import { useAuth } from '../../../context/AuthContext';
import { FIELD_LIMITS } from '../../../utils/fieldLimits';

export default function ClientsToolbar({ searchTerm, onSearchChange, onCreateClick }) {
  const { can } = useAuth();

  return (
    <div className={styles.toolbar}>
      <div className={styles.searchWrap}>
        <span className={styles.searchIcon}>
          <SearchIcon />
        </span>
        <Input
          value={searchTerm}
          onChange={(event) => onSearchChange(event.target.value)}
          maxLength={FIELD_LIMITS.SEARCH}
          placeholder="Buscar por nome, e-mail, telefone ou documento..."
          className={styles.searchInput}
          aria-label="Buscar clientes"
        />
      </div>

      {can('clients.manage') && (
        <Button onClick={onCreateClick}>
          <PlusIcon />
          Novo cliente
        </Button>
      )}
    </div>
  );
}
