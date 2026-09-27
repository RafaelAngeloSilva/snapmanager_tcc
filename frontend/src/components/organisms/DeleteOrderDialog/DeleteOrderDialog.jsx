import { useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { WarningIcon } from '../../atoms/Icon';
import { deleteOrder } from '../../../api/orders';
import styles from './DeleteOrderDialog.module.css';

export default function DeleteOrderDialog({ isOpen, order, onClose, onDeleted }) {
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');

  async function handleConfirm() {
    setIsDeleting(true);
    setError('');

    try {
      await deleteOrder(order.id);
      onDeleted(order);
    } catch {
      setError('Não foi possível excluir o pedido. Tente novamente.');
    } finally {
      setIsDeleting(false);
    }
  }

  if (!order) {
    return null;
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Excluir pedido"
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
            Tem certeza que deseja excluir <strong>{order.title}</strong>?
          </p>
          <p className={styles.hint}>
            O pedido deixa de aparecer na listagem, mas os dados e o histórico de status são mantidos (exclusão
            lógica). Ainda não há tela de restauração.
          </p>
        </div>
      </div>

      {error && <div className={styles.error}>{error}</div>}
    </Modal>
  );
}
