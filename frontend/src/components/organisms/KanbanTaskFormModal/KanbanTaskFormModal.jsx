import { useEffect, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Select from '../../atoms/Select';
import Textarea from '../../atoms/Textarea';
import FormField from '../../molecules/FormField';
import ClientPicker from '../../molecules/ClientPicker';
import DateTimePicker from '../../molecules/DateTimePicker';
import { createTask, updateTask } from '../../../api/kanban';
import { fetchUsers } from '../../../api/users';
import { KANBAN_PRIORITIES } from '../../../utils/kanban';
import { roleLabel } from '../../../utils/permissions';
import styles from './KanbanTaskFormModal.module.css';

const EMPTY_FORM = {
  title: '',
  description: '',
  assigned_to: '',
  priority: 'medium',
  due_date: '',
};
const FORM_ID = 'kanban-task-form';

/**
 * Criação de demanda avulsa e edição de qualquer card.
 *
 * Cards vindos de agendamento/pedido também são editados aqui, mas o
 * vínculo de origem não aparece no formulário: o back-end recusa
 * appointment_id/order_id no update, para não reescrever a procedência do
 * card.
 */
export default function KanbanTaskFormModal({ isOpen, task, column, columns = [], onClose, onSaved }) {
  const isEditing = Boolean(task);

  const [users, setUsers] = useState([]);
  const [selectedClient, setSelectedClient] = useState(null);
  const [columnId, setColumnId] = useState('');
  const [form, setForm] = useState(EMPTY_FORM);
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    fetchUsers()
      .then(setUsers)
      .catch(() => setUsers([]));
  }, []);

  useEffect(() => {
    if (!isOpen) return;

    if (task) {
      setSelectedClient(task.client || null);
      setColumnId(task.kanban_column_id ?? '');
      setForm({
        title: task.title || '',
        description: task.description || '',
        assigned_to: task.assignee?.id ?? '',
        priority: task.priority || 'medium',
        due_date: task.due_date ? task.due_date.slice(0, 10) : '',
      });
    } else {
      setSelectedClient(null);
      setColumnId(column?.id ?? columns[0]?.id ?? '');
      setForm(EMPTY_FORM);
    }

    setErrors({});
    setGeneralError('');
  }, [isOpen, task, column, columns]);

  function handleChange(field) {
    return (event) => setForm((prev) => ({ ...prev, [field]: event.target.value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setErrors({});
    setGeneralError('');
    setIsSubmitting(true);

    const payload = {
      title: form.title,
      description: form.description || null,
      assigned_to: form.assigned_to || null,
      priority: form.priority,
      due_date: form.due_date || null,
      client_id: selectedClient?.id ?? null,
    };

    try {
      const saved = isEditing
        ? await updateTask(task.id, payload)
        : await createTask({ ...payload, kanban_column_id: columnId });

      onSaved(saved);
    } catch (err) {
      if (err.response?.status === 422) {
        const fieldErrors = {};

        Object.entries(err.response.data.errors || {}).forEach(([field, messages]) => {
          fieldErrors[field] = messages[0];
        });

        setErrors(fieldErrors);
      } else if (err.response?.status === 403) {
        setGeneralError('Você não tem permissão para editar este card.');
      } else {
        setGeneralError('Não foi possível salvar o card. Tente novamente.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? 'Editar card' : 'Nova demanda'}
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

        <FormField
          id="kanban-task-title"
          label="Título *"
          value={form.title}
          onChange={handleChange('title')}
          error={errors.title}
          placeholder="Ex.: Tratar fotos do ensaio da Maria"
          required
        />

        {!isEditing && (
          <FormField
            id="kanban-task-column"
            label="Coluna *"
            as={Select}
            value={columnId}
            onChange={(event) => setColumnId(event.target.value)}
            error={errors.kanban_column_id}
            required
          >
            {columns.map((item) => (
              <option key={item.id} value={item.id}>
                {item.name}
              </option>
            ))}
          </FormField>
        )}

        <div className={styles.grid}>
          <FormField
            id="kanban-task-assignee"
            label="Responsável"
            as={Select}
            value={form.assigned_to}
            onChange={handleChange('assigned_to')}
            error={errors.assigned_to}
          >
            <option value="">Sem responsável</option>
            {users.map((user) => (
              <option key={user.id} value={user.id}>
                {user.name} — {roleLabel(user.role)}
              </option>
            ))}
          </FormField>

          <FormField
            id="kanban-task-priority"
            label="Prioridade"
            as={Select}
            value={form.priority}
            onChange={handleChange('priority')}
            error={errors.priority}
          >
            {KANBAN_PRIORITIES.map((priority) => (
              <option key={priority.value} value={priority.value}>
                {priority.label}
              </option>
            ))}
          </FormField>
        </div>

        <DateTimePicker
          id="kanban-task-due-date"
          label="Prazo"
          mode="date"
          value={form.due_date}
          onChange={(value) => setForm((prev) => ({ ...prev, due_date: value }))}
          error={errors.due_date}
        />

        {/* Cliente é opcional aqui: nem toda demanda de produção nasce
            vinculada a um cliente (ex.: "organizar backup do mês"). */}
        <ClientPicker
          label="Cliente (opcional)"
          value={selectedClient}
          onChange={setSelectedClient}
          error={errors.client_id}
        />

        <FormField
          id="kanban-task-description"
          label="Descrição"
          as={Textarea}
          rows={3}
          value={form.description}
          onChange={handleChange('description')}
          error={errors.description}
        />
      </form>
    </Modal>
  );
}
