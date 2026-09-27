import Button from '../../atoms/Button';
import { ChevronLeftIcon, ChevronRightIcon, EditIcon, PlusIcon, TrashIcon } from '../../atoms/Icon';
import styles from './KanbanColumnHeader.module.css';

/**
 * A ordem das colunas é alterada por botões, e não por arrasto: o arrasto
 * dentro do quadro já significa "mover card", e sobrecarregar o mesmo gesto
 * tornaria fácil bagunçar o quadro sem querer. Botões também funcionam por
 * teclado.
 */
export default function KanbanColumnHeader({
  column,
  taskCount,
  canManage,
  canCreateTask,
  canMoveLeft,
  canMoveRight,
  onMove,
  onEdit,
  onDelete,
  onAddTask,
}) {
  return (
    <header className={styles.header}>
      <div className={styles.titleRow}>
        <h2 className={styles.title}>
          {column.name}
          {column.is_done && (
            <span className={styles.doneTag} title="Tarefas nesta coluna são marcadas como concluídas">
              concluída
            </span>
          )}
        </h2>
        <span className={styles.count}>{taskCount}</span>
      </div>

      <div className={styles.actions}>
        {canCreateTask && (
          <Button variant="ghost" size="icon" onClick={() => onAddTask(column)} aria-label={`Nova tarefa em ${column.name}`}>
            <PlusIcon width={15} height={15} />
          </Button>
        )}
        {canManage && (
          <>
            <Button
              variant="ghost"
              size="icon"
              onClick={() => onMove(column, -1)}
              disabled={!canMoveLeft}
              aria-label={`Mover ${column.name} para a esquerda`}
            >
              <ChevronLeftIcon width={15} height={15} />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              onClick={() => onMove(column, 1)}
              disabled={!canMoveRight}
              aria-label={`Mover ${column.name} para a direita`}
            >
              <ChevronRightIcon width={15} height={15} />
            </Button>
            <Button variant="ghost" size="icon" onClick={() => onEdit(column)} aria-label={`Editar ${column.name}`}>
              <EditIcon width={15} height={15} />
            </Button>
            <Button variant="ghost" size="icon" onClick={() => onDelete(column)} aria-label={`Excluir ${column.name}`}>
              <TrashIcon width={15} height={15} />
            </Button>
          </>
        )}
      </div>
    </header>
  );
}
