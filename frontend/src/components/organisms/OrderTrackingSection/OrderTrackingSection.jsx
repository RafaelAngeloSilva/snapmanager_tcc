import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '../../atoms/Badge';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { EyeIcon, TrashIcon } from '../../atoms/Icon';
import CopyLinkButton from '../../molecules/CopyLinkButton';
import InlineAlert from '../../molecules/InlineAlert';
import WhatsAppShareButton from '../../molecules/WhatsAppShareButton';
import ConfirmActionDialog from '../ConfirmActionDialog';
import { enableOrderTracking, orderTrackingUrl, revokeOrderTracking } from '../../../api/orderTracking';
import { useAuth } from '../../../context/AuthContext';
import { formatDateTime } from '../../../utils/formatters';
import { orderTrackingShareMessage } from '../../../utils/whatsapp';
import styles from './OrderTrackingSection.module.css';

/**
 * Mensagem para o funcionário conforme o status HTTP — sem expor detalhes
 * técnicos (o erro completo fica em storage/logs/laravel.log).
 */
function trackingErrorMessage(err, action) {
  const status = err?.response?.status;

  if (status === 403) return `Seu perfil não tem permissão para ${action} o acompanhamento.`;
  if (status === 404) return 'Pedido não encontrado. Ele pode ter sido excluído — feche e abra a lista novamente.';
  if (status === 422) return err.response.data?.message || `Não foi possível ${action} o acompanhamento.`;
  if (!err?.response) return 'Sem conexão com o servidor. Verifique se a API está rodando e tente novamente.';

  return `Erro no servidor ao ${action} o acompanhamento. Tente novamente; se persistir, avise o administrador do sistema.`;
}

/**
 * "Acompanhamento do cliente" (Acompanhe seu Pedido), nos detalhes do pedido.
 *
 * Gera/revoga o link público /acompanhar/{token}, onde o cliente vê a etapa
 * atual do pedido sem login. Ações: ativar, copiar link, enviar por
 * WhatsApp, visualizar como cliente e revogar.
 *
 * Só aparece para quem gerencia pedidos (admin/atendimento) — o back-end só
 * envia `order.tracking` para esses perfis e bloqueia as rotas para os demais.
 *
 * O diálogo de confirmação vai por portal (o painel do modal-pai usa
 * transform/overflow); `onOverlayChange` avisa o pai para não fechar junto.
 */
export default function OrderTrackingSection({ order, onTrackingChange, onOverlayChange }) {
  const { can } = useAuth();
  const tracking = order.tracking;

  const [isEnabling, setIsEnabling] = useState(false);
  const [isRevokeOpen, setIsRevokeOpen] = useState(false);
  const [isRevoking, setIsRevoking] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    onOverlayChange?.(isRevokeOpen);
  }, [isRevokeOpen, onOverlayChange]);

  if (!tracking || !can('orders.tracking')) return null;

  const url = orderTrackingUrl(tracking.token);

  async function handleEnable() {
    if (isEnabling) return; // duplo clique: uma requisição só

    setIsEnabling(true);
    setError('');
    setNotice('');

    try {
      const next = await enableOrderTracking(order.id);
      onTrackingChange(next);
      setNotice('Acompanhamento ativado. Agora é só enviar o link ao cliente.');
    } catch (err) {
      setError(trackingErrorMessage(err, 'ativar'));
    } finally {
      setIsEnabling(false);
    }
  }

  async function handleRevoke() {
    setIsRevoking(true);
    setError('');

    try {
      const next = await revokeOrderTracking(order.id);
      onTrackingChange(next);
      setIsRevokeOpen(false);
      setNotice('Link revogado. Quem tiver o link antigo não consegue mais acessar.');
    } catch (err) {
      setError(trackingErrorMessage(err, 'revogar'));
    } finally {
      setIsRevoking(false);
    }
  }

  function handlePreview() {
    window.open(`${url}?preview=1`, '_blank', 'noopener,noreferrer');
  }

  return (
    <section className={styles.section} aria-labelledby={`order-tracking-${order.id}`}>
      <div className={styles.header}>
        <h3 id={`order-tracking-${order.id}`} className={styles.title}>
          Acompanhamento do cliente
        </h3>
        <Badge variant={tracking.enabled ? 'success' : 'neutral'}>{tracking.enabled ? 'Ativo' : 'Inativo'}</Badge>
      </div>

      {notice && (
        <div className={styles.feedback}>
          <InlineAlert tone="success" onDismiss={() => setNotice('')}>
            {notice}
          </InlineAlert>
        </div>
      )}
      {error && (
        <div className={styles.feedback}>
          <InlineAlert tone="error" onDismiss={() => setError('')}>
            {error}
          </InlineAlert>
        </div>
      )}

      {!tracking.enabled && (
        <div className={styles.inactive}>
          <p className={styles.description}>
            Gere um link para o cliente acompanhar as etapas deste pedido, sem precisar de login. Ele vê só o
            andamento e o prazo, sem valores nem observações internas.
          </p>
          <Button onClick={handleEnable} isLoading={isEnabling}>
            {isEnabling ? <Spinner size="sm" /> : 'Ativar acompanhamento'}
          </Button>
        </div>
      )}

      {tracking.enabled && (
        <>
          <div className={styles.linkBox}>
            <span className={styles.linkLabel}>Link público</span>
            <p className={styles.linkValue}>{url}</p>
            <p className={styles.meta}>
              Ativado em {formatDateTime(tracking.enabled_at)}
              {' · '}
              {tracking.views > 0
                ? `Aberto pelo cliente ${tracking.views} ${tracking.views === 1 ? 'vez' : 'vezes'} (último acesso ${formatDateTime(tracking.last_viewed_at)})`
                : 'O cliente ainda não abriu o link'}
            </p>
          </div>

          <div className={styles.actions}>
            <CopyLinkButton value={url} label="Copiar link público" />
            <WhatsAppShareButton
              phone={order.client?.phone}
              message={orderTrackingShareMessage({ clientName: order.client?.name, title: order.title, url })}
            />
            <Button variant="secondary" onClick={handlePreview}>
              <EyeIcon />
              Visualizar como cliente
            </Button>
            <Button variant="ghost" className={styles.revokeButton} onClick={() => setIsRevokeOpen(true)}>
              <TrashIcon />
              Revogar link
            </Button>
          </div>
        </>
      )}

      {createPortal(
        <ConfirmActionDialog
          isOpen={isRevokeOpen}
          title="Revogar link de acompanhamento"
          message="O link atual deixa de funcionar imediatamente para o cliente."
          hint="Se precisar, você pode ativar de novo depois — um link novo será gerado."
          confirmLabel="Revogar link"
          tone="danger"
          isRunning={isRevoking}
          onConfirm={handleRevoke}
          onClose={() => setIsRevokeOpen(false)}
        />,
        document.body,
      )}
    </section>
  );
}
