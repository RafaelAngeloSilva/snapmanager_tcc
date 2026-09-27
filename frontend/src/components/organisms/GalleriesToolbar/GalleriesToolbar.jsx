import Button from '../../atoms/Button';
import Select from '../../atoms/Select';
import { useAuth } from '../../../context/AuthContext';
import { PlusIcon } from '../../atoms/Icon';
import ClientPicker from '../../molecules/ClientPicker';
import { GALLERY_STATUSES } from '../../../utils/galleryStatus';
import styles from './GalleriesToolbar.module.css';

export default function GalleriesToolbar({
  statusFilter,
  onStatusFilterChange,
  clientFilter,
  onClientFilterChange,
  hasActiveFilters,
  onClearFilters,
  onCreateClick,
}) {
  const { can } = useAuth();

  return (
    <div className={styles.toolbar}>
      <div className={styles.filters}>
        <div className={styles.filterField}>
          <span className={styles.filterLabel}>Status</span>
          <Select value={statusFilter} onChange={(event) => onStatusFilterChange(event.target.value)}>
            <option value="">Todos os status</option>
            {GALLERY_STATUSES.map((status) => (
              <option key={status.value} value={status.value}>
                {status.label}
              </option>
            ))}
          </Select>
        </div>

        <div className={styles.filterFieldClient}>
          <ClientPicker value={clientFilter} onChange={onClientFilterChange} label="Cliente" compact />
        </div>

        {hasActiveFilters && (
          <Button variant="ghost" onClick={onClearFilters}>
            Limpar filtros
          </Button>
        )}
      </div>

      {can('galleries.manage') && (
        <Button onClick={onCreateClick}>
          <PlusIcon />
          Nova galeria
        </Button>
      )}
    </div>
  );
}
