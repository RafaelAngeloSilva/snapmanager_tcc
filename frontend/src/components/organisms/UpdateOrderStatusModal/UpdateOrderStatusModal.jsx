import { useEffect, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Badge from '../../atoms/Badge';
import Select from '../../atoms/Select';
import Textarea from '../../atoms/Textarea';
import FormField from '../../molecules/FormField';
import { updateOrderStatus } from '../../../api/orders';
import { ORDER_STATUSES, ORDER_STATUS_LABELS, ORDER_STATUS_BADGE_VARIANT } from '../../../utils/orderStatus';
import styles from './UpdateOrderStatusModal.module.css';

const FORM_ID = 'order-status-form';

export default function UpdateOrderStatusModal({ isOpen, order, onClose, onUpdated }) {
  const [status, setStatus] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [infoMessage, setInfoMessage] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (!isOpen || !order) return;

    setStatus(order.status || '');
    setNotes('');
    setErrors({});
    setGeneralError('');
    setInfoMessage('');
  }, [isOpen, order]);

  async function handleSubmit(event) {
    event.preventDefault();
    setErrors({});
    setGeneralError('');
    setInfoMessage('');
    setIsSubmitting(true);

    try {
      const response = await updateOrderStatus(order.id, { status, notes: notes || null });
      const statusActuallyChanged = response.data.status !== order.status;

      if (statusActuallyChanged) {
        onUpdated(response.data);
      } else {
        // Backend não duplica histórico quando o status enviado é igual ao
        // atual — avisa o usuário em vez de fechar como se algo tivesse mudado.
        setInfoMessage(response.message);
      }
    } catch (err) {
      if (err.response?.status === 422) {
        const fieldErrors = {};

        Object.entries(err.response.data.errors || {}).forEach(([field, messages]) => {
          fieldErrors[field] = messages[0];
        });

        setErrors(fieldErrors);
      } else {
        setGeneralError('Não foi possível atualizar o status. Tente novamente.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  if (!order) {
    return null;
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Alterar status do pedido"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button type="submit" form={FORM_ID} isLoading={isSubmitting}>
            {isSubmitting ? <Spinner size="sm" /> : 'Confirmar'}
          </Button>
        </>
      }
    >
      <form id={FORM_ID} className={styles.form} onSubmit={handleSubmit}>
        <div className={styles.currentStatus}>
          <span>Status atual:</span>
          <Badge variant={ORDER_STATUS_BADGE_VARIANT[order.status] || 'neutral'}>
            {ORDER_STATUS_LABELS[order.status] || order.status}
          </Badge>
        </div>

        {generalError && <div className={styles.generalError}>{generalError}</div>}
        {infoMessage && <div className={styles.infoMessage}>{infoMessage}</div>}

        <FormField
          id="order-status-select"
          label="Novo status"
          as={Select}
          value={status}
          onChange={(event) => setStatus(event.target.value)}
          error={errors.status}
        >
          {ORDER_STATUSES.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </FormField>

        <FormField
          id="order-status-notes"
          label="Observação (opcional)"
          as={Textarea}
          rows={3}
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
          error={errors.notes}
          placeholder="Ex.: Cliente aprovou o material."
        />
      </form>
    </Modal>
  );
}
