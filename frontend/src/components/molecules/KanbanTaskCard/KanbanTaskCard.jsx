import { memo } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import Avatar from '../../atoms/Avatar';
import PriorityBadge from '../../atoms/PriorityBadge';
import TaskOriginBadge from '../../atoms/TaskOriginBadge';
import { CheckCircleIcon, ClockIcon } from '../../atoms/Icon';
import { formatDueDate, formatDateTime } from '../../../utils/formatters';
import { roleLabel } from '../../../utils/permissions';
import styles from './KanbanTaskCard.module.css';

/**
 * Parte puramente visual do card — sem dnd-kit.
 *
 * Existe separada porque o DragOverlay do quadro precisa desenhar uma cópia
 * do card durante o arrasto. Se essa cópia também chamasse useSortable, o
 * mesmo id ficaria registrado duas vezes no DndContext.
 */
export function KanbanTaskCardView({ task, className = '', innerRef, style, isOverlay = false, ...rest }) {
  const appointmentDate = task.appointment?.starts_at;

  return (
    <article
      ref={innerRef}
      style={style}
      className={[styles.card, task.completed_at ? styles.completed : '', isOverlay ? styles.overlay : '', className]
        .filter(Boolean)
        .join(' ')}
      {...rest}
    >
      <header className={styles.header}>
        <TaskOriginBadge origin={task.origin} />
        {task.completed_at && (
          <span className={styles.doneIcon} title="Concluída">
            <CheckCircleIcon width={15} height={15} />
          </span>
        )}
      </header>

      <h3 className={styles.title}>{task.title}</h3>

      {task.client && <p className={styles.client}>{task.client.name}</p>}

      {appointmentDate && (
        <p className={styles.meta}>
          <ClockIcon width={13} height={13} />
          {formatDateTime(appointmentDate)}
        </p>
      )}

      <footer className={styles.footer}>
        <div className={styles.footerLeft}>
          <PriorityBadge priority={task.priority} />
          {task.due_date && <span className={styles.dueDate}>Prazo: {formatDueDate(task.due_date)}</span>}
        </div>

        {task.assignee && (
          <span className={styles.assignee} title={`${task.assignee.name} — ${roleLabel(task.assignee.role)}`}>
            <Avatar name={task.assignee.name} photoUrl={task.assignee.profile_photo_url} size="sm" />
          </span>
        )}
      </footer>
    </article>
  );
}

/**
 * Card arrastável do quadro. Mostra só o essencial — o resto fica no modal
 * de detalhes, aberto no clique.
 *
 * `disabled` reflete a Policy do back-end: quem não pode mover o card não
 * consegue arrastá-lo (mas o servidor continua respondendo 403 se alguém
 * chamar a API por fora).
 *
 * memo() porque, ao arrastar, o quadro re-renderiza a cada movimento: sem
 * isso, todos os cards seriam recalculados a cada frame.
 */
function KanbanTaskCard({ task, onOpen, disabled = false }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: task.id,
    disabled,
  });

  return (
    <KanbanTaskCardView
      task={task}
      innerRef={setNodeRef}
      style={{ transform: CSS.Translate.toString(transform), transition }}
      className={[isDragging ? styles.dragging : '', disabled ? styles.locked : ''].filter(Boolean).join(' ')}
      {...attributes}
      {...listeners}
      onClick={() => onOpen(task)}
      role="button"
      tabIndex={0}
      onKeyDown={(event) => {
        if (event.key === 'Enter') onOpen(task);
      }}
    />
  );
}

export default memo(KanbanTaskCard);
