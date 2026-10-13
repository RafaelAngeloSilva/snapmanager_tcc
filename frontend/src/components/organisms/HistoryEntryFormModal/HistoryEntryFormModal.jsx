import { useEffect, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Select from '../../atoms/Select';
import Textarea from '../../atoms/Textarea';
import Checkbox from '../../atoms/Checkbox';
import FormField from '../../molecules/FormField';
import DateTimePicker from '../../molecules/DateTimePicker';
import { createHistoryEntry, updateHistoryEntry } from '../../../api/clientHistory';
import { formatDate, todayAsDateValue } from '../../../utils/formatters';
import { FIELD_LIMITS } from '../../../utils/fieldLimits';
import styles from './HistoryEntryFormModal.module.css';

const FORM_ID = 'history-entry-form';

// Mesmo critério do back-end (FieldLimits::NO_HTML_PATTERN): "<b", "</", "<!--".
const HTML_PATTERN = /<\s*\/?\s*[a-z!?]/i;

function oneYearAhead() {
  const date = new Date();
  date.setFullYear(date.getFullYear() + 1);
  const pad = (value) => String(value).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function emptyForm() {
  return { category: '', title: '', event_date: todayAsDateValue(), appointment_id: '', notes: '', is_testimonial_candidate: false };
}

/**
 * Criar/editar uma anotação manual do Histórico. `entry` = evento manual
 * vindo da linha do tempo (edição) ou null (nova anotação).
 */
export default function HistoryEntryFormModal({ isOpen, clientId, entry, categories = [], shoots = [], onClose, onSaved }) {
  const isEditing = Boolean(entry);
  const [form, setForm] = useState(emptyForm);
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (!isOpen) return;

    setForm(
      entry
        ? {
            category: entry.entry.category,
            title: entry.title || '',
            event_date: entry.entry.event_date,
            appointment_id: entry.entry.appointment_id ?? '',
            notes: entry.description || '',
            is_testimonial_candidate: Boolean(entry.entry.is_testimonial_candidate),
          }
        : emptyForm(),
    );
    setErrors({});
    setGeneralError('');
  }, [isOpen, entry]);

  function update(field, value) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  function validate() {
    const next = {};
    const title = form.title.trim();

    if (!form.category) next.category = 'Escolha uma categoria.';
    if (title.length < 2) next.title = 'Informe um título (pelo menos 2 caracteres).';
    if (HTML_PATTERN.test(form.title)) next.title = 'Não use marcações HTML no título.';
    if (HTML_PATTERN.test(form.notes)) next.notes = 'Não use marcações HTML na descrição.';
    if (!form.event_date) next.event_date = 'Informe a data.';

    return next;
  }

  async function handleSubmit(event) {
    event.preventDefault();
    if (isSubmitting) return;

    const localErrors = validate();
    setErrors(localErrors);
    setGeneralError('');
    if (Object.keys(localErrors).length) return;

    const payload = {
      category: form.category,
      title: form.title.trim(),
      event_date: form.event_date,
      appointment_id: form.appointment_id ? Number(form.appointment_id) : null,
      notes: form.notes.trim() || null,
      is_testimonial_candidate: form.category === 'post_sale' && form.is_testimonial_candidate,
    };

    setIsSubmitting(true);

    try {
      const saved = isEditing
        ? await updateHistoryEntry(clientId, entry.entry.id, payload)
        : await createHistoryEntry(clientId, payload);
      onSaved(saved, isEditing);
    } catch (err) {
      if (err.response?.status === 422) {
        const fieldErrors = {};
        Object.entries(err.response.data.errors || {}).forEach(([field, messages]) => {
          fieldErrors[field] = messages[0];
        });
        setErrors(fieldErrors);
      } else if (err.response?.status === 403) {
        setGeneralError('Somente quem criou a anotação (ou um administrador) pode alterá-la.');
      } else if (err.response?.status === 404) {
        setGeneralError('Esta anotação não existe mais. Feche e atualize a página.');
      } else {
        setGeneralError('Não foi possível salvar a anotação. Tente novamente.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? 'Editar anotação' : 'Adicionar anotação'}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button type="submit" form={FORM_ID} disabled={isSubmitting}>
            {isSubmitting ? <Spinner size="sm" /> : 'Salvar'}
          </Button>
        </>
      }
    >
      <form id={FORM_ID} className={styles.form} onSubmit={handleSubmit} noValidate>
        {generalError && (
          <div className={styles.generalError} role="alert">
            {generalError}
          </div>
        )}

        <FormField
          id="history-category"
          label="Categoria *"
          as={Select}
          value={form.category}
          onChange={(event) => update('category', event.target.value)}
          error={errors.category}
          required
        >
          <option value="">Selecione...</option>
          {categories.map((category) => (
            <option key={category.value} value={category.value}>
              {category.label}
            </option>
          ))}
        </FormField>

        <FormField
          id="history-title"
          label="Título *"
          value={form.title}
          maxLength={FIELD_LIMITS.HISTORY_TITLE}
          onChange={(event) => update('title', event.target.value)}
          error={errors.title}
          placeholder="Ex.: Prefere ensaio no fim da tarde"
          required
        />

        <div className={styles.row}>
          <DateTimePicker
            id="history-date"
            label="Data *"
            mode="date"
            value={form.event_date}
            onChange={(value) => update('event_date', value)}
            error={errors.event_date}
            max={oneYearAhead()}
            required
          />

          <FormField
            id="history-appointment"
            label="Ensaio relacionado"
            as={Select}
            value={form.appointment_id}
            onChange={(event) => update('appointment_id', event.target.value)}
            error={errors.appointment_id}
          >
            <option value="">Nenhum</option>
            {shoots.map((shoot) => (
              <option key={shoot.id} value={shoot.id}>
                {shoot.title} — {formatDate(shoot.starts_at)}
              </option>
            ))}
          </FormField>
        </div>

        <FormField
          id="history-notes"
          label="Descrição"
          as={Textarea}
          rows={4}
          value={form.notes}
          maxLength={FIELD_LIMITS.HISTORY_NOTES}
          onChange={(event) => update('notes', event.target.value)}
          error={errors.notes}
          placeholder="O que aconteceu, o que o cliente disse, o que fazer da próxima vez..."
        />

        {form.category === 'post_sale' && (
          <label className={styles.checkboxRow} htmlFor="history-testimonial">
            <Checkbox
              id="history-testimonial"
              checked={form.is_testimonial_candidate}
              onChange={(event) => update('is_testimonial_candidate', event.target.checked)}
            />
            <span>
              Marcar como potencial depoimento
              <small>Use quando o cliente elogiou o trabalho e pode virar um depoimento.</small>
            </span>
          </label>
        )}
      </form>
    </Modal>
  );
}
