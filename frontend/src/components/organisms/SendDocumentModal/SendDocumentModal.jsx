import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { formatDueDate } from '../../../utils/formatters';
import { formatPhone } from '../../../utils/documents';
import styles from './SendDocumentModal.module.css';

/**
 * Revisão antes do envio. Explica o que acontece: o envelope é criado e
 * ATIVADO na Clicksign, mas a solicitação de assinatura só sai quando o
 * usuário clicar em "Enviar solicitação" depois — são passos separados.
 * O botão bloqueia duplo clique (loading + disabled); o back-end também
 * recusa um segundo envio.
 */
export default function SendDocumentModal({ isOpen, document, isSending, error, onConfirm, onClose }) {
  if (!document) return null;

  return (
    <Modal
      isOpen={isOpen}
      onClose={isSending ? undefined : onClose}
      title="Enviar para assinatura"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isSending}>
            Voltar
          </Button>
          <Button onClick={onConfirm} isLoading={isSending}>
            {isSending ? <Spinner size="sm" /> : 'Enviar à Clicksign'}
          </Button>
        </>
      }
    >
      <p className={styles.intro}>
        <strong>{document.title}</strong> será enviado à Clicksign com {document.signers.length} signatário(s):
      </p>

      <ul className={styles.signers}>
        {document.signers.map((signer) => (
          <li key={signer.id}>
            <span className={styles.name}>{signer.name}</span>
            <span className={styles.channel}>
              {signer.channel_label}
              {signer.channel === 'whatsapp' ? ` · ${formatPhone(signer.phone_number)}` : signer.email ? ` · ${signer.email}` : ''}
            </span>
          </li>
        ))}
      </ul>

      {document.signing_file && (
        <p className={styles.deadline}>
          Arquivo que será assinado: <strong>{document.signing_file.name}</strong>
          {document.signing_file.same_as_original ? '' : ' (PDF convertido do Word)'}
        </p>
      )}

      {document.expires_at && <p className={styles.deadline}>Prazo para assinatura: {formatDueDate(document.expires_at)}</p>}

      <div className={styles.steps}>
        <p className={styles.stepsTitle}>O que acontece agora</p>
        <ol>
          <li>O envelope é criado e ativado na Clicksign com o PDF e os signatários.</li>
          <li>
            Depois, use <strong>Enviar solicitação</strong> para que cada signatário receba o convite pelo canal escolhido.
          </li>
        </ol>
        <p className={styles.warning}>
          Depois de enviado, o documento não pode mais ser editado e os dados dos signatários não podem ser alterados.
        </p>
      </div>

      {error && (
        <div className={styles.error} role="alert">
          {error}
        </div>
      )}
    </Modal>
  );
}
