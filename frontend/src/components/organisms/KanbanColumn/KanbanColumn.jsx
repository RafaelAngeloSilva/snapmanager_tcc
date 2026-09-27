import { useDroppable } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import KanbanColumnHeader from '../../molecules/KanbanColumnHeader';
import KanbanTaskCard from '../../molecules/KanbanTaskCard';
import styles from './KanbanColumn.module.css';

/**
 * Uma coluna do quadro: cabeçalho + lista ordenável de cards.
 *
 * O useDroppable garante que a coluna continue sendo um alvo válido mesmo
 * quando está vazia (sem ele, uma coluna sem cards não receberia nada).
 */
export default function KanbanColumn({
  column,
  canManageColumns,
  canCreateTask,
  canDragTask,
  canMoveLeft,
  canMoveRight,
  onMoveColumn,
  onOpenTask,
  onEditColumn,
  onDeleteColumn,
  onAddTask,
}) {
  const { setNodeRef, isOver } = useDroppable({
    id: `column-${column.id}`,
    data: { type: 'column', columnId: column.id },
  });

  const taskIds = column.tasks.map((task) => task.id);

  return (
    <section className={`${styles.column} ${isOver ? styles.columnOver : ''}`}>
      <KanbanColumnHeader
        column={column}
        taskCount={column.tasks.length}
        canManage={canManageColumns}
        canCreateTask={canCreateTask}
        canMoveLeft={canMoveLeft}
        canMoveRight={canMoveRight}
        onMove={onMoveColumn}
        onEdit={onEditColumn}
        onDelete={onDeleteColumn}
        onAddTask={onAddTask}
      />

      <div ref={setNodeRef} className={styles.taskList}>
        <SortableContext items={taskIds} strategy={verticalListSortingStrategy}>
          {column.tasks.map((task) => (
            <KanbanTaskCard key={task.id} task={task} onOpen={onOpenTask} disabled={!canDragTask(task)} />
          ))}
        </SortableContext>

        {column.tasks.length === 0 && <p className={styles.empty}>Arraste um card para cá</p>}
      </div>
    </section>
  );
}
