import Input from '../../atoms/Input';
import { SearchIcon } from '../../atoms/Icon';
import { DOCUMENT_FILTERS } from '../../../utils/documents';
import styles from './DocumentFilters.module.css';
import { FIELD_LIMITS } from '../../../utils/fieldLimits';

/**
 * Filtro por grupo de status + busca por título ou cliente. Os dois são
 * aplicados no back-end (viram query string); o debounce da busca fica na
 * página.
 */
export default function DocumentFilters({ status, search, onStatusChange, onSearchChange }) {
  return (
    <div className={styles.filters}>
      <div className={styles.toggleGroup} role="group" aria-label="Filtrar por status">
        {DOCUMENT_FILTERS.map((filter) => (
          <button
            key={filter.value || 'all'}
            type="button"
            className={`${styles.toggle} ${status === filter.value ? styles.toggleActive : ''}`}
            aria-pressed={status === filter.value}
            onClick={() => onStatusChange(filter.value)}
          >
            {filter.label}
          </button>
        ))}
      </div>

      <label className={styles.search}>
        <SearchIcon className={styles.searchIcon} aria-hidden="true" />
        <Input
          type="search"
          value={search}
          onChange={(event) => onSearchChange(event.target.value)}
          maxLength={FIELD_LIMITS.SEARCH}
          placeholder="Buscar por título ou cliente..."
          aria-label="Buscar por título ou cliente"
        />
      </label>
    </div>
  );
}
