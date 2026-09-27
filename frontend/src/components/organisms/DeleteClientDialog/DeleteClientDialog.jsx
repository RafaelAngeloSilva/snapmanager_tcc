import { useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { WarningIcon } from '../../atoms/Icon';
import { deleteClient } from '../../../api/clients';
import styles from './DeleteClientDialog.module.css';

export default function DeleteClientDialog({ isOpen, client, onClose, onDeleted }) {
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');

  async function handleConfirm() {
    setIsDeleting(true);
    setError('');

    try {
      await deleteClient(client.id);
      onDeleted(client);
    } catch {
      setError('Não foi possível excluir o cliente. Tente novamente.');
    } finally {
      setIsDeleting(false);
    }
  }

  if (!client) {
    return null;
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Excluir cliente"
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
            Tem certeza que deseja excluir <strong>{client.name}</strong>?
          </p>
          <p className={styles.hint}>
            O cliente deixa de aparecer na listagem, mas os dados são mantidos (exclusão lógica). Ainda não há
            tela de restauração.
          </p>
        </div>
      </div>

      {error && <div className={styles.error}>{error}</div>}
    </Modal>
  );
}
