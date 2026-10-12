import { useCallback, useEffect, useState } from 'react';
import Card from '../../atoms/Card';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import { PlusIcon } from '../../atoms/Icon';
import KanbanFilters from '../../molecules/KanbanFilters';
import KanbanBoard from '../../organisms/KanbanBoard';
import KanbanTaskModal from '../../organisms/KanbanTaskModal';
import KanbanTaskFormModal from '../../organisms/KanbanTaskFormModal';
import KanbanColumnFormModal from '../../organisms/KanbanColumnFormModal';
import DeleteKanbanColumnDialog from '../../organisms/DeleteKanbanColumnDialog';
import DeleteKanbanTaskDialog from '../../organisms/DeleteKanbanTaskDialog';
import { fetchBoard, reorderColumns } from '../../../api/kanban';
import { fetchUsers } from '../../../api/users';
import { useAuth } from '../../../context/AuthContext';
import { canManageTask } from '../../../utils/kanban';
import styles from './Production.module.css';

const EMPTY_FILTERS = { assignedTo: '', priority: '', origin: '' };

export default function Production() {
  const { user, can } = useAuth();

  const [columns, setColumns] = useState([]);
  const [users, setUsers] = useState([]);
  const [filters, setFilters] = useState(EMPTY_FILTERS);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState('');
  const [actionError, setActionError] = useState('');

  const [taskModal, setTaskModal] = useState({ isOpen: false, task: null });
  const [taskForm, setTaskForm] = useState({ isOpen: false, task: null, column: null });
  const [columnForm, setColumnForm] = useState({ isOpen: false, column: null });
  const [columnDelete, setColumnDelete] = useState({ isOpen: false, column: null });
  const [taskDelete, setTaskDelete] = useState({ isOpen: false, task: null });

  const canManageColumns = can('kanban.manageColumns');
  const canCreateTask = can('kanban.createTask');

  const load = useCallback((activeFilters) => {
    setIsLoading(true);
    setError('');

    fetchBoard(activeFilters)
      .then(setColumns)
      .catch(() => setError('Não foi possível carregar o quadro de produção.'))
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    load(filters);
  }, [load, filters]);

  useEffect(() => {
    fetchUsers()
      .then(setUsers)
      .catch(() => setUsers([]));
  }, []);

  /** Substitui um card já presente no quadro, sem recarregar tudo da API. */
  function replaceTask(saved) {
    setColumns((prev) =>
      prev.map((column) => ({
        ...column,
        tasks: column.tasks.map((task) => (task.id === saved.id ? saved : task)),
      })),
    );
  }

  function handleOpenTask(task) {
    setTaskModal({ isOpen: true, task });
  }

  /** Mesma estratégia do arrasto de cards: move na tela, salva depois, desfaz se falhar. */
  async function handleMoveColumn(column, direction) {
    const index = columns.findIndex((item) => item.id === column.id);
    const target = index + direction;

    if (index === -1 || target < 0 || target >= columns.length) return;

    const previous = columns;
    const reordered = [...columns];
    reordered.splice(target, 0, reordered.splice(index, 1)[0]);

    setColumns(reordered);
    setActionError('');

    try {
      await reorderColumns(reordered.map((item) => item.id));
    } catch {
      setColumns(previous);
      setActionError('Não foi possível reordenar as colunas. A ordem anterior foi restaurada.');
    }
  }

  function handleTaskSaved(saved) {
    setTaskForm({ isOpen: false, task: null, column: null });

    // Criar um card pode mudar a ordem das colunas afetadas, então aqui
    // vale recarregar; editar só troca o conteúdo de um card já visível.
    const exists = columns.some((column) => column.tasks.some((task) => task.id === saved.id));

    if (exists) {
      replaceTask(saved);
      setTaskModal((prev) => (prev.isOpen && prev.task?.id === saved.id ? { isOpen: true, task: saved } : prev));
    } else {
      load(filters);
    }
  }

  function handleTaskDeleted() {
    setTaskDelete({ isOpen: false, task: null });
    setTaskModal({ isOpen: false, task: null });
    load(filters);
  }

  function handleColumnSaved() {
    setColumnForm({ isOpen: false, column: null });
    load(filters);
  }

  function handleColumnDeleted() {
    setColumnDelete({ isOpen: false, column: null });
    load(filters);
  }

  const openTask = taskModal.task;
  const openTaskColumn = openTask ? columns.find((column) => column.id === openTask.kanban_column_id) : null;
  const hasCards = columns.some((column) => column.tasks.length > 0);
  const hasActiveFilters = Boolean(filters.assignedTo || filters.priority || filters.origin);

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Produção</h1>
        <p className="page-subtitle">Quadro de trabalho da equipe, alimentado pela agenda e pelos pedidos.</p>
      </div>

      <div className={styles.toolbar}>
        <KanbanFilters
          filters={filters}
          users={users}
          onChange={setFilters}
          onReset={() => setFilters(EMPTY_FILTERS)}
        />

        <div className={styles.actions}>
          {canManageColumns && (
            <Button variant="secondary" onClick={() => setColumnForm({ isOpen: true, column: null })}>
              <PlusIcon />
              Nova coluna
            </Button>
          )}
          {canCreateTask && columns.length > 0 && (
            <Button onClick={() => setTaskForm({ isOpen: true, task: null, column: columns[0] })}>
              <PlusIcon />
              Nova demanda
            </Button>
          )}
        </div>
      </div>

      {actionError && (
        <div className={styles.actionError} role="alert">
          {actionError}
          <button type="button" className={styles.dismiss} onClick={() => setActionError('')} aria-label="Fechar aviso">
            ✕
          </button>
        </div>
      )}

      {isLoading && (
        <Card>
          <div className={styles.loadingBox}>
            <Spinner /> Carregando quadro...
          </div>
        </Card>
      )}

      {!isLoading && error && (
        <Card>
          <EmptyState
            tone="error"
            layout="row"
            title="Erro ao carregar o quadro"
            message={error}
            action={
              <Button variant="danger" onClick={() => load(filters)}>
                Tentar novamente
              </Button>
            }
          />
        </Card>
      )}

      {!isLoading && !error && columns.length === 0 && (
        <Card>
          <EmptyState
            title="Nenhuma coluna no quadro"
            message={
              canManageColumns
                ? 'Crie a primeira coluna para começar a organizar a produção.'
                : 'Um administrador precisa criar as colunas do quadro antes de começar.'
            }
            action={
              canManageColumns ? (
                <Button onClick={() => setColumnForm({ isOpen: true, column: null })}>Nova coluna</Button>
              ) : null
            }
          />
        </Card>
      )}

      {!isLoading && !error && columns.length > 0 && (
        <>
          {!hasCards && hasActiveFilters && (
            <p className={styles.filterNotice}>Nenhum card corresponde aos filtros selecionados.</p>
          )}

          <KanbanBoard
            columns={columns}
            setColumns={setColumns}
            canManageColumns={canManageColumns}
            canCreateTask={canCreateTask}
            canDragTask={(task) => canManageTask(user, task)}
            onOpenTask={handleOpenTask}
            onEditColumn={(column) => setColumnForm({ isOpen: true, column })}
            onDeleteColumn={(column) => setColumnDelete({ isOpen: true, column })}
            onMoveColumn={handleMoveColumn}
            onAddTask={(column) => setTaskForm({ isOpen: true, task: null, column })}
            onTaskMoved={replaceTask}
            onError={setActionError}
          />
        </>
      )}

      <KanbanTaskModal
        isOpen={taskModal.isOpen}
        task={openTask}
        column={openTaskColumn}
        canManage={canManageTask(user, openTask)}
        onClose={() => setTaskModal({ isOpen: false, task: null })}
        onEdit={(task) => {
          setTaskModal({ isOpen: false, task: null });
          setTaskForm({ isOpen: true, task, column: null });
        }}
        onDelete={(task) => setTaskDelete({ isOpen: true, task })}
      />

      <KanbanTaskFormModal
        isOpen={taskForm.isOpen}
        task={taskForm.task}
        column={taskForm.column}
        columns={columns}
        onClose={() => setTaskForm({ isOpen: false, task: null, column: null })}
        onSaved={handleTaskSaved}
      />

      <KanbanColumnFormModal
        isOpen={columnForm.isOpen}
        column={columnForm.column}
        onClose={() => setColumnForm({ isOpen: false, column: null })}
        onSaved={handleColumnSaved}
      />

      <DeleteKanbanColumnDialog
        isOpen={columnDelete.isOpen}
        column={columnDelete.column}
        columns={columns}
        onClose={() => setColumnDelete({ isOpen: false, column: null })}
        onDeleted={handleColumnDeleted}
      />

      <DeleteKanbanTaskDialog
        isOpen={taskDelete.isOpen}
        task={taskDelete.task}
        onClose={() => setTaskDelete({ isOpen: false, task: null })}
        onDeleted={handleTaskDeleted}
      />
    </div>
  );
}
