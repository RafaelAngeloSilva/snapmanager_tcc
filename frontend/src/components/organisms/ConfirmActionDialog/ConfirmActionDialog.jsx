import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { WarningIcon } from '../../atoms/Icon';
import styles from './ConfirmActionDialog.module.css';

/**
 * Confirmação para ações de documento (cancelar, excluir, descartar envio,
 * notificar). O botão fica em loading e desabilitado enquanto a ação roda,
 * então um duplo clique não dispara duas requisições.
 */
export default function ConfirmActionDialog({
  isOpen,
  title,
  message,
  hint,
  confirmLabel = 'Confirmar',
  tone = 'danger',
  isRunning = false,
  error = '',
  onConfirm,
  onClose,
}) {
  return (
    <Modal
      isOpen={isOpen}
      onClose={isRunning ? undefined : onClose}
      title={title}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isRunning}>
            Voltar
          </Button>
          <Button variant={tone === 'danger' ? 'danger' : 'primary'} onClick={onConfirm} isLoading={isRunning}>
            {isRunning ? <Spinner size="sm" /> : confirmLabel}
          </Button>
        </>
      }
    >
      <div className={styles.body}>
        <span className={`${styles.iconWrap} ${tone === 'danger' ? styles.danger : styles.primary}`}>
          <WarningIcon />
        </span>
        <div>
          <p className={styles.message}>{message}</p>
          {hint && <p className={styles.hint}>{hint}</p>}
        </div>
      </div>

      {error && (
        <div className={styles.error} role="alert">
          {error}
        </div>
      )}
    </Modal>
  );
}
