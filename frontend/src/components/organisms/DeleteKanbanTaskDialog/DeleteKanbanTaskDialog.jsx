import { useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { WarningIcon } from '../../atoms/Icon';
import { deleteTask } from '../../../api/kanban';
import styles from './DeleteKanbanTaskDialog.module.css';

export default function DeleteKanbanTaskDialog({ isOpen, task, onClose, onDeleted }) {
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');

  async function handleConfirm() {
    setIsDeleting(true);
    setError('');

    try {
      await deleteTask(task.id);
      onDeleted(task);
    } catch (err) {
      setError(
        err.response?.status === 403
          ? 'Você não tem permissão para excluir este card.'
          : 'Não foi possível excluir o card. Tente novamente.',
      );
    } finally {
      setIsDeleting(false);
    }
  }

  if (!task) {
    return null;
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Excluir card"
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
            Tem certeza que deseja excluir <strong>{task.title}</strong> do quadro?
          </p>
          <p className={styles.hint}>
            {task.origin === 'manual'
              ? 'O card sai do quadro (exclusão lógica). Nenhum outro registro é afetado.'
              : 'Apenas o card sai do quadro — o agendamento ou pedido de origem continua intacto e poderá ser adicionado de novo depois.'}
          </p>
        </div>
      </div>

      {error && <div className={styles.error}>{error}</div>}
    </Modal>
  );
}
