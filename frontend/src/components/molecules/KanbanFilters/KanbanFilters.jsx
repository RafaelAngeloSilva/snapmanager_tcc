import Button from '../../atoms/Button';
import Select from '../../atoms/Select';
import { KANBAN_ORIGINS, KANBAN_PRIORITIES } from '../../../utils/kanban';
import { roleLabel } from '../../../utils/permissions';
import styles from './KanbanFilters.module.css';

/**
 * Filtros do quadro. Todos são aplicados no back-end (viram query string) —
 * "Minhas tarefas" é um atalho para assigned_to=me.
 */
export default function KanbanFilters({ filters, users, onChange, onReset }) {
  const hasActiveFilters = Boolean(filters.assignedTo || filters.priority || filters.origin);

  return (
    <div className={styles.filters}>
      <div className={styles.toggleGroup} role="group" aria-label="Escopo das tarefas">
        <button
          type="button"
          className={`${styles.toggle} ${!filters.assignedTo ? styles.toggleActive : ''}`}
          onClick={() => onChange({ ...filters, assignedTo: '' })}
        >
          Todas
        </button>
        <button
          type="button"
          className={`${styles.toggle} ${filters.assignedTo === 'me' ? styles.toggleActive : ''}`}
          onClick={() => onChange({ ...filters, assignedTo: 'me' })}
        >
          Minhas tarefas
        </button>
      </div>

      <div className={styles.field}>
        <span className={styles.label}>Responsável</span>
        <Select
          value={filters.assignedTo === 'me' ? '' : filters.assignedTo}
          onChange={(event) => onChange({ ...filters, assignedTo: event.target.value })}
        >
          <option value="">Todos</option>
          {users.map((user) => (
            <option key={user.id} value={user.id}>
              {user.name} — {roleLabel(user.role)}
            </option>
          ))}
        </Select>
      </div>

      <div className={styles.field}>
        <span className={styles.label}>Prioridade</span>
        <Select
          value={filters.priority}
          onChange={(event) => onChange({ ...filters, priority: event.target.value })}
        >
          <option value="">Todas</option>
          {KANBAN_PRIORITIES.map((priority) => (
            <option key={priority.value} value={priority.value}>
              {priority.label}
            </option>
          ))}
        </Select>
      </div>

      <div className={styles.field}>
        <span className={styles.label}>Origem</span>
        <Select value={filters.origin} onChange={(event) => onChange({ ...filters, origin: event.target.value })}>
          <option value="">Todas</option>
          {KANBAN_ORIGINS.map((origin) => (
            <option key={origin.value} value={origin.value}>
              {origin.label}
            </option>
          ))}
        </Select>
      </div>

      {hasActiveFilters && (
        <Button variant="ghost" onClick={onReset}>
          Limpar filtros
        </Button>
      )}
    </div>
  );
}
