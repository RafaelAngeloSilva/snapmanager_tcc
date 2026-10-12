import { useEffect, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Select from '../../atoms/Select';
import Textarea from '../../atoms/Textarea';
import FormField from '../../molecules/FormField';
import ClientPicker from '../../molecules/ClientPicker';
import DateTimePicker from '../../molecules/DateTimePicker';
import { createAppointment, updateAppointment } from '../../../api/appointments';
import { fetchUsers } from '../../../api/users';
import { toDatetimeLocalValue, nowAsDatetimeLocalValue } from '../../../utils/formatters';
import { APPOINTMENT_STATUSES } from '../../../utils/appointmentStatus';
import styles from './AppointmentFormModal.module.css';
import { FIELD_LIMITS } from '../../../utils/fieldLimits';

const EMPTY_FORM = {
  user_id: '',
  title: '',
  session_type: '',
  starts_at: '',
  ends_at: '',
  location: '',
  status: 'scheduled',
  notes: '',
};
const FORM_ID = 'appointment-form';

export default function AppointmentFormModal({ isOpen, appointment, onClose, onSaved }) {
  const isEditing = Boolean(appointment);

  const [users, setUsers] = useState([]);
  const [selectedClient, setSelectedClient] = useState(null);
  const [form, setForm] = useState(EMPTY_FORM);
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Não força "min" no horário de início se o agendamento em edição já
  // começou/terminou no passado (ex.: status "completed") — senão o
  // navegador bloqueia a edição de qualquer outro campo por causa da
  // validação nativa do <input>. O back-end aplica a mesma lógica de "só
  // valida se estiver mudando de fato" (ver ChecksAppointmentSchedule).
  const nowLocal = nowAsDatetimeLocalValue();
  const startsAtMin = !isEditing || toDatetimeLocalValue(appointment?.starts_at) >= nowLocal ? nowLocal : undefined;

  useEffect(() => {
    fetchUsers()
      .then(setUsers)
      .catch(() => setUsers([]));
  }, []);

  useEffect(() => {
    if (!isOpen) return;

    if (appointment) {
      setSelectedClient(appointment.client || null);
      setForm({
        user_id: appointment.user?.id ?? '',
        title: appointment.title || '',
        session_type: appointment.session_type || '',
        starts_at: toDatetimeLocalValue(appointment.starts_at),
        ends_at: toDatetimeLocalValue(appointment.ends_at),
        location: appointment.location || '',
        status: appointment.status || 'scheduled',
        notes: appointment.notes || '',
      });
    } else {
      setSelectedClient(null);
      setForm(EMPTY_FORM);
    }

    setErrors({});
    setGeneralError('');
  }, [isOpen, appointment]);

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

    if (startsAtMin && form.starts_at && form.starts_at < startsAtMin) {
      setErrors({ starts_at: 'A data do agendamento não pode ser anterior à data atual.' });
      return;
    }

    if (form.starts_at && form.ends_at && form.ends_at <= form.starts_at) {
      setErrors({ ends_at: 'O horário de término deve ser posterior ao horário de início.' });
      return;
    }

    setIsSubmitting(true);

    const payload = {
      client_id: selectedClient.id,
      user_id: form.user_id || null,
      title: form.title,
      session_type: form.session_type || null,
      starts_at: form.starts_at,
      ends_at: form.ends_at,
      location: form.location || null,
      status: form.status,
      notes: form.notes || null,
    };

    try {
      const saved = isEditing ? await updateAppointment(appointment.id, payload) : await createAppointment(payload);
      onSaved(saved);
    } catch (err) {
      if (err.response?.status === 422) {
        const fieldErrors = {};

        Object.entries(err.response.data.errors || {}).forEach(([field, messages]) => {
          fieldErrors[field] = messages[0];
        });

        setErrors(fieldErrors);
      } else {
        setGeneralError('Não foi possível salvar o agendamento. Tente novamente.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? 'Editar agendamento' : 'Novo agendamento'}
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
          id="appointment-title"
          maxLength={FIELD_LIMITS.TITLE}
          label="Título *"
          value={form.title}
          onChange={handleChange('title')}
          error={errors.title}
          placeholder="Ex.: Ensaio gestante - Maria"
          required
        />

        <div className={styles.grid}>
          <FormField
            id="appointment-session-type"
            maxLength={FIELD_LIMITS.CATEGORY}
            label="Tipo de sessão"
            value={form.session_type}
            onChange={handleChange('session_type')}
            error={errors.session_type}
            placeholder="Ensaio, casamento, evento..."
          />

          <FormField
            id="appointment-user"
            label="Profissional responsável"
            as={Select}
            value={form.user_id}
            onChange={handleChange('user_id')}
            error={errors.user_id}
          >
            <option value="">Nenhum</option>
            {users.map((user) => (
              <option key={user.id} value={user.id}>
                {user.name} ({user.role})
              </option>
            ))}
          </FormField>
        </div>

        <div className={styles.grid}>
          <DateTimePicker
            id="appointment-starts-at"
            label="Início *"
            value={form.starts_at}
            onChange={(value) => setForm((prev) => ({ ...prev, starts_at: value }))}
            error={errors.starts_at}
            min={startsAtMin}
            required
          />

          <DateTimePicker
            id="appointment-ends-at"
            label="Término *"
            value={form.ends_at}
            onChange={(value) => setForm((prev) => ({ ...prev, ends_at: value }))}
            error={errors.ends_at}
            // O término nunca pode ser antes do início: o calendário já
            // desabilita os dias anteriores, e a validação do formulário e
            // do back-end continuam valendo.
            min={form.starts_at || undefined}
            required
          />
        </div>

        <div className={styles.grid}>
          <FormField
            id="appointment-location"
            maxLength={FIELD_LIMITS.LOCATION}
            label="Local"
            value={form.location}
            onChange={handleChange('location')}
            error={errors.location}
          />

          <FormField
            id="appointment-status"
            label="Status"
            as={Select}
            value={form.status}
            onChange={handleChange('status')}
            error={errors.status}
          >
            {APPOINTMENT_STATUSES.map((status) => (
              <option key={status.value} value={status.value}>
                {status.label}
              </option>
            ))}
          </FormField>
        </div>

        <FormField
          id="appointment-notes"
          maxLength={FIELD_LIMITS.NOTES}
          label="Observações"
          as={Textarea}
          rows={3}
          value={form.notes}
          onChange={handleChange('notes')}
          error={errors.notes}
        />
      </form>
    </Modal>
  );
}
