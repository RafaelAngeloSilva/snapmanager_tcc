import { useCallback, useEffect, useRef, useState } from 'react';
import {
  DndContext,
  DragOverlay,
  KeyboardSensor,
  PointerSensor,
  closestCorners,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import { arrayMove, sortableKeyboardCoordinates } from '@dnd-kit/sortable';
import KanbanColumn from '../KanbanColumn';
import { KanbanTaskCardView } from '../../molecules/KanbanTaskCard/KanbanTaskCard';
import { moveTask } from '../../../api/kanban';
import styles from './KanbanBoard.module.css';

/** O droppable da coluna usa um id com prefixo para não colidir com ids de card. */
const COLUMN_DROPPABLE_PREFIX = 'column-';

/** Resolve qual coluna está sob o ponteiro: o próprio corpo da coluna ou o card sobre o qual se está passando. */
function resolveColumn(columns, overId) {
  if (typeof overId === 'string' && overId.startsWith(COLUMN_DROPPABLE_PREFIX)) {
    const columnId = Number(overId.slice(COLUMN_DROPPABLE_PREFIX.length));

    return columns.find((column) => column.id === columnId) || null;
  }

  return columns.find((column) => column.tasks.some((task) => task.id === overId)) || null;
}

function findTaskLocation(columns, taskId) {
  for (const column of columns) {
    const index = column.tasks.findIndex((task) => task.id === taskId);

    if (index !== -1) {
      return { column, index };
    }
  }

  return null;
}

/**
 * Quadro de produção: orquestra o drag-and-drop e a persistência.
 *
 * A regra de fluidez é: mover primeiro, salvar depois. O estado local muda
 * no instante do "drop", a API é chamada em seguida e, se ela falhar, o
 * quadro volta exatamente para o arranjo anterior (snapshot tirado no início
 * do arrasto). O usuário nunca espera a rede para ver o card mudar de lugar.
 */
export default function KanbanBoard({
  columns,
  setColumns,
  canManageColumns,
  canCreateTask,
  canDragTask,
  onOpenTask,
  onEditColumn,
  onDeleteColumn,
  onMoveColumn,
  onAddTask,
  onTaskMoved,
  onError,
}) {
  const [activeTask, setActiveTask] = useState(null);
  const boardRef = useRef(null);

  // Só visual: informa ao CSS onde o quadro começa na página, para que em
  // telas largas ele ocupe o resto da janela e a barra de rolagem
  // horizontal fique à vista (ver KanbanBoard.module.css). Não interfere no
  // arrasto: o dnd-kit continua rolando o quadro e as colunas normalmente.
  useEffect(() => {
    const board = boardRef.current;
    if (!board) return undefined;

    function updateOffset() {
      const top = board.getBoundingClientRect().top + window.scrollY;
      board.style.setProperty('--kanban-board-offset', `${Math.round(top + 24)}px`);
    }

    updateOffset();
    window.addEventListener('resize', updateOffset);
    const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(updateOffset) : null;
    if (board.parentElement) observer?.observe(board.parentElement);

    return () => {
      window.removeEventListener('resize', updateOffset);
      observer?.disconnect();
    };
  }, []);

  // Arranjo do quadro no início do arrasto — usado para desfazer se a API falhar.
  const snapshotRef = useRef(null);

  const sensors = useSensors(
    // A distância mínima evita que um clique simples (abrir detalhes) seja
    // interpretado como início de arrasto.
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const handleDragStart = useCallback(
    ({ active }) => {
      snapshotRef.current = columns;
      setActiveTask(findTaskLocation(columns, active.id)?.column.tasks.find((task) => task.id === active.id) || null);
    },
    [columns],
  );

  // Move o card entre colunas já durante o arrasto, para que o espaço se abra
  // sob o cursor. O reposicionamento final acontece no drag end.
  const handleDragOver = useCallback(
    ({ active, over }) => {
      if (!over) return;

      const from = findTaskLocation(columns, active.id);
      const target = resolveColumn(columns, over.id);

      if (!from || !target || from.column.id === target.id) return;

      const task = from.column.tasks[from.index];
      const overIndex = target.tasks.findIndex((item) => item.id === over.id);
      const insertAt = overIndex === -1 ? target.tasks.length : overIndex;

      setColumns(
        columns.map((column) => {
          if (column.id === from.column.id) {
            return { ...column, tasks: column.tasks.filter((item) => item.id !== active.id) };
          }

          if (column.id === target.id) {
            const tasks = [...column.tasks];
            tasks.splice(insertAt, 0, task);

            return { ...column, tasks };
          }

          return column;
        }),
      );
    },
    [columns, setColumns],
  );

  async function handleDragEnd({ active, over }) {
    const snapshot = snapshotRef.current;

    setActiveTask(null);
    snapshotRef.current = null;

    if (!over) {
      if (snapshot) setColumns(snapshot);

      return;
    }

    // Depois do drag over, o card já está na coluna de destino.
    const current = findTaskLocation(columns, active.id);

    if (!current) return;

    const overIndex = current.column.tasks.findIndex((task) => task.id === over.id);
    const targetIndex = overIndex === -1 ? current.column.tasks.length - 1 : overIndex;

    const before = snapshot ? findTaskLocation(snapshot, active.id) : null;
    const unchanged = before && before.column.id === current.column.id && before.index === targetIndex;

    if (unchanged) {
      setColumns(snapshot);

      return;
    }

    const reordered = arrayMove(current.column.tasks, current.index, targetIndex);
    const optimistic = columns.map((column) =>
      column.id === current.column.id ? { ...column, tasks: reordered } : column,
    );

    setColumns(optimistic);

    try {
      // O back-end devolve o card já com completed_at preenchido/limpo
      // conforme a coluna de destino seja de conclusão ou não.
      const saved = await moveTask(active.id, {
        columnId: current.column.id,
        position: targetIndex,
      });

      onTaskMoved?.(saved);
    } catch (err) {
      if (snapshot) setColumns(snapshot);

      onError?.(
        err.response?.status === 403
          ? 'Você não tem permissão para mover este card.'
          : 'Não foi possível mover o card. A posição anterior foi restaurada.',
      );
    }
  }

  function handleDragCancel() {
    if (snapshotRef.current) setColumns(snapshotRef.current);

    snapshotRef.current = null;
    setActiveTask(null);
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCorners}
      onDragStart={handleDragStart}
      onDragOver={handleDragOver}
      onDragEnd={handleDragEnd}
      onDragCancel={handleDragCancel}
    >
      <div ref={boardRef} className={styles.board}>
        {columns.map((column, index) => (
          <KanbanColumn
            key={column.id}
            column={column}
            canManageColumns={canManageColumns}
            canCreateTask={canCreateTask}
            canDragTask={canDragTask}
            canMoveLeft={index > 0}
            canMoveRight={index < columns.length - 1}
            onMoveColumn={onMoveColumn}
            onOpenTask={onOpenTask}
            onEditColumn={onEditColumn}
            onDeleteColumn={onDeleteColumn}
            onAddTask={onAddTask}
          />
        ))}
      </div>

      <DragOverlay dropAnimation={{ duration: 180, easing: 'cubic-bezier(0.2, 0, 0, 1)' }}>
        {activeTask ? <KanbanTaskCardView task={activeTask} isOverlay /> : null}
      </DragOverlay>
    </DndContext>
  );
}
