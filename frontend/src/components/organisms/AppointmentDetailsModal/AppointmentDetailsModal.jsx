import { useEffect, useState } from 'react';
import LinkedDocumentsSection from '../LinkedDocumentsSection';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Badge from '../../atoms/Badge';
import EmptyState from '../../atoms/EmptyState';
import { fetchAppointment } from '../../../api/appointments';
import { formatDateTime } from '../../../utils/formatters';
import { APPOINTMENT_STATUS_LABELS, APPOINTMENT_STATUS_BADGE_VARIANT } from '../../../utils/appointmentStatus';
import styles from './AppointmentDetailsModal.module.css';

export default function AppointmentDetailsModal({ isOpen, appointmentId, onClose, onEdit }) {
  const [appointment, setAppointment] = useState(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState('');
  // Um modal de documento aberto por cima: Esc/clique fora não fecham o agendamento junto.
  const [hasDocumentOverlay, setHasDocumentOverlay] = useState(false);

  useEffect(() => {
    if (!isOpen || !appointmentId) return;

    setIsLoading(true);
    setError('');
    setAppointment(null);

    fetchAppointment(appointmentId)
      .then(setAppointment)
      .catch(() => setError('Não foi possível carregar os detalhes do agendamento.'))
      .finally(() => setIsLoading(false));
  }, [isOpen, appointmentId]);

  return (
    <Modal
      isOpen={isOpen}
      onClose={hasDocumentOverlay ? undefined : onClose}
      title="Detalhes do agendamento"
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Fechar
          </Button>
          {appointment && <Button onClick={() => onEdit(appointment)}>Editar</Button>}
        </>
      }
    >
      {isLoading && (
        <div className={styles.loadingBox}>
          <Spinner /> Carregando...
        </div>
      )}

      {!isLoading && error && <EmptyState tone="error" title="Erro" message={error} />}

      {!isLoading && !error && appointment && (
        <>
          <div className={styles.header}>
            <h2 className={styles.title}>{appointment.title}</h2>
            <Badge variant={APPOINTMENT_STATUS_BADGE_VARIANT[appointment.status] || 'neutral'}>
              {APPOINTMENT_STATUS_LABELS[appointment.status] || appointment.status}
            </Badge>
          </div>

          <dl className={styles.details}>
            <div>
              <dt>Cliente</dt>
              <dd>{appointment.client?.name || 'Cliente removido'}</dd>
            </div>
            <div>
              <dt>Profissional responsável</dt>
              <dd>{appointment.user?.name || '—'}</dd>
            </div>
            <div>
              <dt>Tipo de sessão</dt>
              <dd>{appointment.session_type || '—'}</dd>
            </div>
            <div>
              <dt>Início</dt>
              <dd>{formatDateTime(appointment.starts_at)}</dd>
            </div>
            <div>
              <dt>Término</dt>
              <dd>{formatDateTime(appointment.ends_at)}</dd>
            </div>
            <div>
              <dt>Local</dt>
              <dd>{appointment.location || '—'}</dd>
            </div>
            <div className={styles.fullWidth}>
              <dt>Observações</dt>
              <dd>{appointment.notes || '—'}</dd>
            </div>
          </dl>

          <LinkedDocumentsSection
            context="appointment"
            contextId={appointment.id}
            client={appointment.client}
            presetAppointment={appointment}
            onOverlayChange={setHasDocumentOverlay}
          />
        </>
      )}
    </Modal>
  );
}
