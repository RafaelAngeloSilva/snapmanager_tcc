import Button from '../../atoms/Button';
import SignerStatusBadge from '../../atoms/SignerStatusBadge';
import { TrashIcon } from '../../atoms/Icon';
import { formatDateTime } from '../../../utils/formatters';
import { formatPhone } from '../../../utils/documents';
import styles from './SignerRow.module.css';

/**
 * Um signatário na tela de detalhes. E-mail/telefone só aparecem quando o
 * back-end os envia (perfis que gerenciam documentos).
 */
export default function SignerRow({ signer, onRemove, isRemoving = false }) {
  const contact = signer.channel === 'whatsapp' ? formatPhone(signer.phone_number) : signer.email;

  return (
    <li className={styles.row}>
      <div className={styles.info}>
        <span className={styles.name}>{signer.name}</span>
        <span className={styles.meta}>
          <span className={styles.channel}>{signer.channel_label}</span>
          {contact && <span>· {contact}</span>}
          {signer.signed_at && <span>· assinou em {formatDateTime(signer.signed_at)}</span>}
        </span>
      </div>

      <div className={styles.side}>
        <SignerStatusBadge signer={signer} />
        {onRemove && (
          <Button
            variant="ghost"
            size="icon"
            onClick={() => onRemove(signer)}
            isLoading={isRemoving}
            aria-label={`Remover ${signer.name}`}
          >
            <TrashIcon width={15} height={15} />
          </Button>
        )}
      </div>
    </li>
  );
}
