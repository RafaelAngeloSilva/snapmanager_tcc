import { formatDateTime } from '../../../utils/formatters';
import styles from './DocumentTimeline.module.css';

// Tons do marcador por tipo de evento — só para leitura rápida do histórico.
const TONE = {
  signed: 'success',
  signer_signed: 'success',
  signed_file_stored: 'success',
  sent: 'primary',
  notified: 'primary',
  integration_error: 'danger',
  signer_declined: 'danger',
  canceled: 'muted',
  expired: 'muted',
  remote_draft_discarded: 'muted',
};

/**
 * Histórico do processo, do mais recente para o mais antigo. Eventos sem
 * usuário vieram da Clicksign (webhook/sincronização).
 */
export default function DocumentTimeline({ events }) {
  if (!events?.length) {
    return <p className={styles.empty}>Nenhum evento registrado ainda.</p>;
  }

  return (
    <ol className={styles.timeline}>
      {events.map((event) => (
        <li key={event.id} className={styles.item}>
          <span className={`${styles.marker} ${styles[TONE[event.event] || 'neutral']}`} aria-hidden="true" />
          <div className={styles.content}>
            <p className={styles.label}>{event.label}</p>
            {event.description && <p className={styles.description}>{event.description}</p>}
            <p className={styles.meta}>
              {event.user ? event.user.name : 'Clicksign / sistema'} · {formatDateTime(event.created_at)}
            </p>
          </div>
        </li>
      ))}
    </ol>
  );
}
