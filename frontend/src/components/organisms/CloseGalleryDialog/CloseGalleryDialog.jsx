import { useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { LockIcon } from '../../atoms/Icon';
import { closeGallery } from '../../../api/galleries';
import styles from './CloseGalleryDialog.module.css';

export default function CloseGalleryDialog({ isOpen, gallery, onClose, onClosed }) {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState('');

  async function handleConfirm() {
    setIsSubmitting(true);
    setError('');

    try {
      await closeGallery(gallery.id);
      onClosed(gallery);
    } catch {
      setError('Não foi possível encerrar a galeria. Tente novamente.');
    } finally {
      setIsSubmitting(false);
    }
  }

  if (!gallery) {
    return null;
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Encerrar galeria"
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button variant="danger" onClick={handleConfirm} isLoading={isSubmitting}>
            {isSubmitting ? <Spinner size="sm" /> : 'Encerrar galeria'}
          </Button>
        </>
      }
    >
      <div className={styles.body}>
        <span className={styles.iconWrap}>
          <LockIcon />
        </span>
        <div>
          <p className={styles.message}>
            Tem certeza que deseja encerrar <strong>{gallery.title}</strong>?
          </p>
          <p className={styles.hint}>
            O link público passa a exibir uma mensagem de galeria indisponível e o cliente não conseguirá mais
            selecionar fotos. As fotos e a seleção já feita continuam visíveis aqui no painel.
          </p>
        </div>
      </div>

      {error && <div className={styles.error}>{error}</div>}
    </Modal>
  );
}
