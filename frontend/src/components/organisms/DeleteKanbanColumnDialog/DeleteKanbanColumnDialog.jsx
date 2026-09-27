import { useEffect, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Select from '../../atoms/Select';
import { WarningIcon } from '../../atoms/Icon';
import { deleteColumn } from '../../../api/kanban';
import styles from './DeleteKanbanColumnDialog.module.css';

/**
 * Excluir coluna nunca apaga cards em silêncio: se a coluna tem tarefas, é
 * obrigatório escolher para onde elas vão. A mesma regra existe no
 * back-end (DestroyKanbanColumnRequest devolve 422 sem `move_tasks_to`),
 * então este select é conveniência, não a garantia.
 */
export default function DeleteKanbanColumnDialog({ isOpen, column, columns = [], onClose, onDeleted }) {
  const [destination, setDestination] = useState('');
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');

  const others = columns.filter((item) => item.id !== column?.id);
  const taskCount = column?.tasks?.length ?? 0;
  const needsDestination = taskCount > 0;

  useEffect(() => {
    if (!isOpen) return;

    setDestination(others[0]?.id ?? '');
    setError('');
  }, [isOpen, column]); // eslint-disable-line react-hooks/exhaustive-deps

  async function handleConfirm() {
    if (needsDestination && !destination) {
      setError('Escolha para qual coluna as tarefas devem ir.');

      return;
    }

    setIsDeleting(true);
    setError('');

    try {
      await deleteColumn(column.id, { moveTasksTo: needsDestination ? destination : undefined });
      onDeleted(column);
    } catch (err) {
      if (err.response?.status === 409) {
        setError('O quadro precisa ter pelo menos uma coluna.');
      } else if (err.response?.status === 422) {
        setError(err.response.data.errors?.move_tasks_to?.[0] || 'Não foi possível excluir a coluna.');
      } else if (err.response?.status === 403) {
        setError('Apenas administradores podem excluir colunas.');
      } else {
        setError('Não foi possível excluir a coluna. Tente novamente.');
      }
    } finally {
      setIsDeleting(false);
    }
  }

  if (!column) {
    return null;
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Excluir coluna"
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isDeleting}>
            Cancelar
          </Button>
          <Button variant="danger" onClick={handleConfirm} isLoading={isDeleting}>
            {isDeleting ? <Spinner size="sm" /> : 'Excluir'}
          </Button>
        </>
      }
    >
      <div className={styles.body}>
        <span className={styles.iconWrap}>
          <WarningIcon />
        </span>
        <div>
          <p className={styles.message}>
            Tem certeza que deseja excluir a coluna <strong>{column.name}</strong>?
          </p>
          <p className={styles.hint}>
            {needsDestination
              ? `A coluna tem ${taskCount} ${taskCount === 1 ? 'card' : 'cards'}. Nenhum deles é apagado — escolha abaixo para onde vão.`
              : 'A coluna está vazia, nenhum card será afetado.'}
          </p>
        </div>
      </div>

      {needsDestination && (
        <div className={styles.field}>
          <span className={styles.label}>Mover os cards para *</span>
          <Select value={destination} onChange={(event) => setDestination(event.target.value)}>
            {others.map((item) => (
              <option key={item.id} value={item.id}>
                {item.name}
              </option>
            ))}
          </Select>
        </div>
      )}

      {error && <div className={styles.error}>{error}</div>}
    </Modal>
  );
}
