import { useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { WarningIcon } from '../../atoms/Icon';
import { deleteGallery } from '../../../api/galleries';
import styles from './DeleteGalleryDialog.module.css';

export default function DeleteGalleryDialog({ isOpen, gallery, onClose, onDeleted }) {
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');

  async function handleConfirm() {
    setIsDeleting(true);
    setError('');

    try {
      await deleteGallery(gallery.id);
      onDeleted(gallery);
    } catch {
      setError('Não foi possível excluir a galeria. Tente novamente.');
    } finally {
      setIsDeleting(false);
    }
  }

  if (!gallery) {
    return null;
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Excluir galeria"
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
            Tem certeza que deseja excluir <strong>{gallery.title}</strong>?
          </p>
          <p className={styles.hint}>
            O link público deixa de funcionar imediatamente. A galeria e as fotos continuam no banco de dados
            (exclusão lógica), mas somem da listagem.
          </p>
        </div>
      </div>

      {error && <div className={styles.error}>{error}</div>}
    </Modal>
  );
}
