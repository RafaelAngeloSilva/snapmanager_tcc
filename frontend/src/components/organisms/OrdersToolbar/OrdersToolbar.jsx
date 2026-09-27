import Button from '../../atoms/Button';
import Select from '../../atoms/Select';
import DateTimePicker from '../../molecules/DateTimePicker';
import { useAuth } from '../../../context/AuthContext';
import { PlusIcon } from '../../atoms/Icon';
import ClientPicker from '../../molecules/ClientPicker';
import { ORDER_STATUSES } from '../../../utils/orderStatus';
import styles from './OrdersToolbar.module.css';

export default function OrdersToolbar({
  statusFilter,
  onStatusFilterChange,
  clientFilter,
  onClientFilterChange,
  dueDateFilter,
  onDueDateFilterChange,
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
            {ORDER_STATUSES.map((status) => (
              <option key={status.value} value={status.value}>
                {status.label}
              </option>
            ))}
          </Select>
        </div>

        <div className={styles.filterFieldClient}>
          <ClientPicker value={clientFilter} onChange={onClientFilterChange} label="Cliente" compact />
        </div>

        <div className={styles.filterField}>
          <DateTimePicker
            id="orders-due-date-filter"
            label="Prazo de entrega"
            mode="date"
            value={dueDateFilter}
            onChange={onDueDateFilterChange}
            placeholder="Qualquer data"
          />
        </div>

        {hasActiveFilters && (
          <Button variant="ghost" onClick={onClearFilters}>
            Limpar filtros
          </Button>
        )}
      </div>

      {can('orders.manage') && (
        <Button onClick={onCreateClick}>
          <PlusIcon />
          Novo pedido
        </Button>
      )}
    </div>
  );
}
