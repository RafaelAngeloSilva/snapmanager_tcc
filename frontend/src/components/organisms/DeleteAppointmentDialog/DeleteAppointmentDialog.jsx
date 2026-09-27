import { useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { WarningIcon } from '../../atoms/Icon';
import { deleteAppointment } from '../../../api/appointments';
import styles from './DeleteAppointmentDialog.module.css';

export default function DeleteAppointmentDialog({ isOpen, appointment, onClose, onDeleted }) {
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');

  async function handleConfirm() {
    setIsDeleting(true);
    setError('');

    try {
      await deleteAppointment(appointment.id);
      onDeleted(appointment);
    } catch {
      setError('Não foi possível excluir o agendamento. Tente novamente.');
    } finally {
      setIsDeleting(false);
    }
  }

  if (!appointment) {
    return null;
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Excluir agendamento"
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
            Tem certeza que deseja excluir <strong>{appointment.title}</strong>?
          </p>
          <p className={styles.hint}>
            O agendamento deixa de aparecer na agenda, mas os dados são mantidos (exclusão lógica). Ainda não há
            tela de restauração.
          </p>
        </div>
      </div>

      {error && <div className={styles.error}>{error}</div>}
    </Modal>
  );
}
