import { useEffect, useState } from 'react';
import LinkedDocumentsSection from '../LinkedDocumentsSection';
import OrderTrackingSection from '../OrderTrackingSection';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Badge from '../../atoms/Badge';
import EmptyState from '../../atoms/EmptyState';
import { fetchOrder } from '../../../api/orders';
import { formatCurrency, formatDueDate, formatDateTime } from '../../../utils/formatters';
import { ORDER_STATUS_LABELS, ORDER_STATUS_BADGE_VARIANT, isOrderOverdue } from '../../../utils/orderStatus';
import styles from './OrderDetailsModal.module.css';

export default function OrderDetailsModal({ isOpen, orderId, onClose, onEdit, onChangeStatus }) {
  const [order, setOrder] = useState(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState('');
  // Um modal de documento aberto por cima: Esc/clique fora não fecham o pedido junto.
  const [hasDocumentOverlay, setHasDocumentOverlay] = useState(false);
  // Idem para o diálogo "Revogar link" do acompanhamento do cliente.
  const [hasTrackingOverlay, setHasTrackingOverlay] = useState(false);

  useEffect(() => {
    if (!isOpen || !orderId) return;

    setIsLoading(true);
    setError('');
    setOrder(null);

    fetchOrder(orderId)
      .then(setOrder)
      .catch(() => setError('Não foi possível carregar os detalhes do pedido.'))
      .finally(() => setIsLoading(false));
  }, [isOpen, orderId]);

  const overdue = order ? isOrderOverdue(order) : false;

  return (
    <Modal
      isOpen={isOpen}
      onClose={hasDocumentOverlay || hasTrackingOverlay ? undefined : onClose}
      title="Detalhes do pedido"
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Fechar
          </Button>
          {order && (
            <Button variant="secondary" onClick={() => onChangeStatus(order)}>
              Alterar status
            </Button>
          )}
          {order && <Button onClick={() => onEdit(order)}>Editar</Button>}
        </>
      }
    >
      {isLoading && (
        <div className={styles.loadingBox}>
          <Spinner /> Carregando...
        </div>
      )}

      {!isLoading && error && <EmptyState tone="error" title="Erro" message={error} />}

      {!isLoading && !error && order && (
        <>
          <div className={styles.header}>
            <h2 className={styles.title}>{order.title}</h2>
            <Badge variant={ORDER_STATUS_BADGE_VARIANT[order.status] || 'neutral'}>
              {ORDER_STATUS_LABELS[order.status] || order.status}
            </Badge>
          </div>

          <dl className={styles.details}>
            <div>
              <dt>Cliente</dt>
              <dd>{order.client?.name || 'Cliente removido'}</dd>
            </div>
            <div>
              <dt>Agendamento relacionado</dt>
              <dd>{order.appointment?.title || '—'}</dd>
            </div>
            <div>
              <dt>Tipo de serviço</dt>
              <dd>{order.service_type || '—'}</dd>
            </div>
            <div>
              <dt>Prazo de entrega</dt>
              <dd className={overdue ? styles.overdue : ''}>
                {order.due_date ? formatDueDate(order.due_date) : '—'}
                {overdue && ' (atrasado)'}
              </dd>
            </div>
            <div>
              <dt>Valor total</dt>
              <dd>{formatCurrency(order.total)}</dd>
            </div>
            <div>
              <dt>Criado em</dt>
              <dd>{formatDateTime(order.created_at)}</dd>
            </div>
            <div className={styles.fullWidth}>
              <dt>Descrição</dt>
              <dd>{order.description || '—'}</dd>
            </div>
            <div className={styles.fullWidth}>
              <dt>Observações</dt>
              <dd>{order.notes || '—'}</dd>
            </div>
          </dl>

          <LinkedDocumentsSection
            context="order"
            contextId={order.id}
            client={order.client}
            presetOrder={order}
            presetAppointment={order.appointment}
            onOverlayChange={setHasDocumentOverlay}
          />

          {/* Acompanhe seu Pedido — link público do cliente. */}
          <OrderTrackingSection
            order={order}
            onTrackingChange={(tracking) => setOrder((current) => (current ? { ...current, tracking } : current))}
            onOverlayChange={setHasTrackingOverlay}
          />

          <p className={`${styles.sectionTitle} ${styles.historyTitle}`}>Histórico de status</p>

          {(!order.status_histories || order.status_histories.length === 0) && (
            <p className={styles.emptyHistory}>Nenhum registro de histórico ainda.</p>
          )}

          {order.status_histories && order.status_histories.length > 0 && (
            <ul className={styles.historyList}>
              {order.status_histories.map((history) => (
                <li key={history.id} className={styles.historyItem}>
                  <div className={styles.historyTransition}>
                    {history.from_status ? (
                      <>
                        <Badge variant={ORDER_STATUS_BADGE_VARIANT[history.from_status] || 'neutral'}>
                          {ORDER_STATUS_LABELS[history.from_status] || history.from_status}
                        </Badge>
                        <span>→</span>
                      </>
                    ) : (
                      <span>Criado como</span>
                    )}
                    <Badge variant={ORDER_STATUS_BADGE_VARIANT[history.to_status] || 'neutral'}>
                      {ORDER_STATUS_LABELS[history.to_status] || history.to_status}
                    </Badge>
                  </div>
                  <p className={styles.historyMeta}>
                    {history.changed_by?.name || 'Usuário removido'} · {formatDateTime(history.created_at)}
                  </p>
                  {history.notes && <p className={styles.historyNotes}>{history.notes}</p>}
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </Modal>
  );
}
