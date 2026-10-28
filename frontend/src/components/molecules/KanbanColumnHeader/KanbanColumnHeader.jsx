import Button from '../../atoms/Button';
import ActionMenu from '../ActionMenu';
import { ChevronLeftIcon, ChevronRightIcon, EditIcon, PlusIcon, TrashIcon } from '../../atoms/Icon';
import styles from './KanbanColumnHeader.module.css';

/**
 * Cabeçalho compacto: "+" (nova tarefa) sempre à vista e as ações de
 * administração (mover, editar, excluir) no menu "⋯" — com os cinco
 * botões soltos, nomes como "EM ANDAMENTO" eram cortados.
 *
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
        <h2 className={styles.title} title={column.name}>
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
          <ActionMenu
            label={`Mais ações para a coluna ${column.name}`}
            items={[
              {
                key: 'left',
                label: 'Mover para a esquerda',
                icon: <ChevronLeftIcon width={15} height={15} />,
                onSelect: () => onMove(column, -1),
                disabled: !canMoveLeft,
              },
              {
                key: 'right',
                label: 'Mover para a direita',
                icon: <ChevronRightIcon width={15} height={15} />,
                onSelect: () => onMove(column, 1),
                disabled: !canMoveRight,
              },
              { key: 'edit', label: 'Editar coluna', icon: <EditIcon width={15} height={15} />, onSelect: () => onEdit(column) },
              { key: 'delete', label: 'Excluir coluna', icon: <TrashIcon width={15} height={15} />, onSelect: () => onDelete(column) },
            ]}
          />
        )}
      </div>
    </header>
  );
}
