import { useEffect, useMemo, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Select from '../../atoms/Select';
import Textarea from '../../atoms/Textarea';
import FormField from '../../molecules/FormField';
import ClientPicker from '../../molecules/ClientPicker';
import DateTimePicker from '../../molecules/DateTimePicker';
import { createOrder, updateOrder } from '../../../api/orders';
import { fetchAppointments } from '../../../api/appointments';
import { formatDateTime, todayAsDateValue } from '../../../utils/formatters';
import styles from './OrderFormModal.module.css';

const EMPTY_FORM = {
  appointment_id: '',
  title: '',
  service_type: '',
  description: '',
  due_date: '',
  total: '',
  notes: '',
};
const FORM_ID = 'order-form';

export default function OrderFormModal({ isOpen, order, onClose, onSaved }) {
  const isEditing = Boolean(order);

  const [appointments, setAppointments] = useState([]);
  const [selectedClient, setSelectedClient] = useState(null);
  const [form, setForm] = useState(EMPTY_FORM);
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Mesma lógica do agendamento: não força "min" se o pedido em edição já
  // está com prazo vencido (um pedido atrasado é um estado normal do
  // sistema — não pode travar a edição dos outros campos). O back-end só
  // valida "não pode ser passado" quando a data está realmente mudando.
  const todayValue = todayAsDateValue();
  const dueDateMin = !isEditing || (order?.due_date && order.due_date.slice(0, 10) >= todayValue) ? todayValue : undefined;

  useEffect(() => {
    // Só a primeira página, como combinado: não vira um módulo de agenda
    // aqui, é só um select simples com título + data.
    fetchAppointments({ page: 1 })
      .then((response) => setAppointments(response.data))
      .catch(() => setAppointments([]));
  }, []);

  useEffect(() => {
    if (!isOpen) return;

    if (order) {
      setSelectedClient(order.client || null);
      setForm({
        appointment_id: order.appointment?.id ?? '',
        title: order.title || '',
        service_type: order.service_type || '',
        description: order.description || '',
        due_date: order.due_date ? order.due_date.slice(0, 10) : '',
        total: order.total ?? '',
        notes: order.notes || '',
      });
    } else {
      setSelectedClient(null);
      setForm(EMPTY_FORM);
    }

    setErrors({});
    setGeneralError('');
  }, [isOpen, order]);

  // Se o pedido em edição tem um agendamento fora da primeira página
  // carregada, garante que ele apareça como opção selecionável mesmo assim.
  const appointmentOptions = useMemo(() => {
    if (!order?.appointment) return appointments;

    const alreadyIncluded = appointments.some((appointment) => appointment.id === order.appointment.id);

    return alreadyIncluded ? appointments : [order.appointment, ...appointments];
  }, [appointments, order]);

  function handleChange(field) {
    return (event) => setForm((prev) => ({ ...prev, [field]: event.target.value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setErrors({});
    setGeneralError('');

    if (!selectedClient) {
      setErrors({ client_id: 'Selecione um cliente.' });
      return;
    }

    if (dueDateMin && form.due_date && form.due_date < dueDateMin) {
      setErrors({ due_date: 'A data de entrega não pode ser anterior à data atual.' });
      return;
    }

    setIsSubmitting(true);

    const payload = {
      client_id: selectedClient.id,
      appointment_id: form.appointment_id || null,
      title: form.title,
      service_type: form.service_type || null,
      description: form.description || null,
      due_date: form.due_date || null,
      notes: form.notes || null,
    };

    // "total" segue a mesma regra do backend: só envia se preenchido, senão
    // deixa o default (0) do banco valer.
    if (form.total !== '') {
      payload.total = form.total;
    }

    try {
      const saved = isEditing ? await updateOrder(order.id, payload) : await createOrder(payload);
      onSaved(saved);
    } catch (err) {
      if (err.response?.status === 422) {
        const fieldErrors = {};

        Object.entries(err.response.data.errors || {}).forEach(([field, messages]) => {
          fieldErrors[field] = messages[0];
        });

        setErrors(fieldErrors);
      } else {
        setGeneralError('Não foi possível salvar o pedido. Tente novamente.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? 'Editar pedido' : 'Novo pedido'}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button type="submit" form={FORM_ID} isLoading={isSubmitting}>
            {isSubmitting ? <Spinner size="sm" /> : 'Salvar'}
          </Button>
        </>
      }
    >
      <form id={FORM_ID} className={styles.form} onSubmit={handleSubmit}>
        {generalError && <div className={styles.generalError}>{generalError}</div>}

        <ClientPicker value={selectedClient} onChange={setSelectedClient} error={errors.client_id} />

        <FormField
          id="order-appointment"
          label="Agendamento relacionado"
          as={Select}
          value={form.appointment_id}
          onChange={handleChange('appointment_id')}
          error={errors.appointment_id}
        >
          <option value="">Nenhum</option>
          {appointmentOptions.map((appointment) => (
            <option key={appointment.id} value={appointment.id}>
              {appointment.title} — {formatDateTime(appointment.starts_at)}
            </option>
          ))}
        </FormField>

        <FormField
          id="order-title"
          label="Título *"
          value={form.title}
          onChange={handleChange('title')}
          error={errors.title}
          placeholder="Ex.: Álbum 30x30 - Casamento"
          required
        />

        <div className={styles.grid}>
          <FormField
            id="order-service-type"
            label="Tipo de serviço"
            value={form.service_type}
            onChange={handleChange('service_type')}
            error={errors.service_type}
            placeholder="Álbum, impressão, mídia digital..."
          />

          <DateTimePicker
            id="order-due-date"
            label="Prazo de entrega"
            mode="date"
            value={form.due_date}
            onChange={(value) => setForm((prev) => ({ ...prev, due_date: value }))}
            error={errors.due_date}
            min={dueDateMin}
          />
        </div>

        <FormField
          id="order-total"
          label="Valor total (R$)"
          type="number"
          min="0"
          step="0.01"
          value={form.total}
          onChange={handleChange('total')}
          error={errors.total}
          placeholder="0,00"
        />

        <FormField
          id="order-description"
          label="Descrição"
          as={Textarea}
          rows={2}
          value={form.description}
          onChange={handleChange('description')}
          error={errors.description}
        />

        <FormField
          id="order-notes"
          label="Observações"
          as={Textarea}
          rows={2}
          value={form.notes}
          onChange={handleChange('notes')}
          error={errors.notes}
        />
      </form>
    </Modal>
  );
}
