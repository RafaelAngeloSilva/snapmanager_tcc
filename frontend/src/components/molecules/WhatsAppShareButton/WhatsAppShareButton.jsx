import Button from '../../atoms/Button';
import { ChatIcon } from '../../atoms/Icon';
import { normalizeWhatsappPhone, whatsappShareUrl } from '../../../utils/whatsapp';
import styles from './WhatsAppShareButton.module.css';

/**
 * Abre o WhatsApp com a mensagem pronta (link wa.me). Com telefone válido,
 * já abre a conversa do cliente; sem telefone, abre para escolher o
 * contato. Nada é enviado automaticamente — quem confirma o envio é o
 * funcionário, dentro do WhatsApp.
 */
export default function WhatsAppShareButton({ phone, message, label = 'Enviar por WhatsApp' }) {
  const hasRecipient = Boolean(normalizeWhatsappPhone(phone));
  const url = whatsappShareUrl({ phone, message });

  return (
    <div className={styles.wrapper}>
      <Button
        variant="secondary"
        className={styles.button}
        onClick={() => window.open(url, '_blank', 'noopener,noreferrer')}
        title={hasRecipient ? 'Abrir conversa do cliente no WhatsApp com a mensagem pronta' : 'Abrir o WhatsApp com a mensagem pronta para escolher o contato'}
      >
        <ChatIcon />
        {label}
      </Button>

      {!hasRecipient && <span className={styles.hint}>Cliente sem telefone válido: você escolhe o contato no WhatsApp.</span>}
    </div>
  );
}
