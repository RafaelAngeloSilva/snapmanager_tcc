import { useEffect, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Checkbox from '../../atoms/Checkbox';
import FormField from '../../molecules/FormField';
import { createColumn, updateColumn } from '../../../api/kanban';
import styles from './KanbanColumnFormModal.module.css';

const FORM_ID = 'kanban-column-form';

/**
 * Criar ou renomear coluna. Só administradores chegam aqui — a rota
 * correspondente está sob `role:admin`, então esconder o botão é conforto,
 * não segurança.
 */
export default function KanbanColumnFormModal({ isOpen, column, onClose, onSaved }) {
  const isEditing = Boolean(column);

  const [name, setName] = useState('');
  const [isDone, setIsDone] = useState(false);
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (!isOpen) return;

    setName(column?.name || '');
    setIsDone(Boolean(column?.is_done));
    setErrors({});
    setGeneralError('');
  }, [isOpen, column]);

  async function handleSubmit(event) {
    event.preventDefault();
    setErrors({});
    setGeneralError('');
    setIsSubmitting(true);

    const payload = { name, is_done: isDone };

    try {
      const saved = isEditing ? await updateColumn(column.id, payload) : await createColumn(payload);

      onSaved(saved);
    } catch (err) {
      if (err.response?.status === 422) {
        const fieldErrors = {};

        Object.entries(err.response.data.errors || {}).forEach(([field, messages]) => {
          fieldErrors[field] = messages[0];
        });

        setErrors(fieldErrors);
      } else if (err.response?.status === 403) {
        setGeneralError('Apenas administradores podem gerenciar as colunas do quadro.');
      } else {
        setGeneralError('Não foi possível salvar a coluna. Tente novamente.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? 'Editar coluna' : 'Nova coluna'}
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
          id="kanban-column-name"
          label="Nome *"
          value={name}
          onChange={(event) => setName(event.target.value)}
          error={errors.name}
          placeholder="Ex.: Em edição"
          maxLength={60}
          required
        />

        <label className={styles.checkboxRow} htmlFor="kanban-column-is-done">
          <Checkbox
            id="kanban-column-is-done"
            checked={isDone}
            onChange={(event) => setIsDone(event.target.checked)}
          />
          Coluna de conclusão
        </label>

        <p className={styles.hint}>
          Cards movidos para uma coluna de conclusão recebem data de conclusão automaticamente; ao sair dela, a data é
          limpa.
        </p>
      </form>
    </Modal>
  );
}
