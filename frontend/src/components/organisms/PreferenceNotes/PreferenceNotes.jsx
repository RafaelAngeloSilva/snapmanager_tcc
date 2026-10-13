import { useEffect, useRef, useState } from 'react';
import Button from '../../atoms/Button';
import Textarea from '../../atoms/Textarea';
import Spinner from '../../atoms/Spinner';
import { EditIcon, NoteIcon } from '../../atoms/Icon';
import { updatePreferenceNotes } from '../../../api/clientHistory';
import { FIELD_LIMITS } from '../../../utils/fieldLimits';
import styles from './PreferenceNotes.module.css';

const PLACEHOLDER =
  'Ex.: Prefere fotos espontâneas. Não gosta de sorrir mostrando os dentes. Melhor lado: perfil esquerdo. Evitar horários muito cedo.';

/**
 * "Notas de Preferência": como o cliente gosta de ser atendido e
 * fotografado. Visual de bloco de notas discreto; salva pelo botão Salvar
 * (mesmo padrão dos formulários do sistema).
 */
export default function PreferenceNotes({ clientId, notes, canEdit, maxLength = FIELD_LIMITS.PREFERENCE_NOTES, onSaved }) {
  const [isEditing, setIsEditing] = useState(false);
  const [draft, setDraft] = useState(notes || '');
  const [error, setError] = useState('');
  const [isSaving, setIsSaving] = useState(false);
  const textareaRef = useRef(null);

  useEffect(() => {
    if (!isEditing) setDraft(notes || '');
  }, [notes, isEditing]);

  useEffect(() => {
    if (isEditing) textareaRef.current?.focus();
  }, [isEditing]);

  function startEditing() {
    setDraft(notes || '');
    setError('');
    setIsEditing(true);
  }

  async function handleSave() {
    if (isSaving) return;

    if (draft.length > maxLength) {
      setError(`Use no máximo ${maxLength} caracteres.`);
      return;
    }

    setIsSaving(true);
    setError('');

    try {
      const saved = await updatePreferenceNotes(clientId, draft);
      onSaved?.(saved);
      setIsEditing(false);
    } catch (err) {
      setError(
        err.response?.data?.errors?.preference_notes?.[0] ||
          (err.response?.status === 403
            ? 'Você não tem permissão para editar estas notas.'
            : 'Não foi possível salvar. Tente novamente.'),
      );
    } finally {
      setIsSaving(false);
    }
  }

  function handleKeyDown(event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      setIsEditing(false);
    }

    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
      event.preventDefault();
      handleSave();
    }
  }

  const remaining = maxLength - draft.length;

  return (
    <section className={styles.note} aria-labelledby="preference-notes-title">
      <header className={styles.header}>
        <h2 id="preference-notes-title" className={styles.title}>
          <NoteIcon width={16} height={16} aria-hidden="true" />
          Notas de Preferência
        </h2>

        {canEdit && !isEditing && (
          <Button variant="ghost" onClick={startEditing}>
            <EditIcon />
            {notes ? 'Editar' : 'Escrever'}
          </Button>
        )}
      </header>

      {!isEditing &&
        (notes ? (
          <p className={styles.text}>{notes}</p>
        ) : (
          <p className={styles.empty}>
            Como este cliente gosta de ser atendido e fotografado? Anote aqui o que vale lembrar no próximo ensaio.
          </p>
        ))}

      {isEditing && (
        <div className={styles.editor}>
          <Textarea
            ref={textareaRef}
            id="preference-notes"
            rows={6}
            value={draft}
            maxLength={maxLength}
            invalid={Boolean(error)}
            placeholder={PLACEHOLDER}
            onChange={(event) => setDraft(event.target.value)}
            onKeyDown={handleKeyDown}
            className={styles.textarea}
            aria-label="Notas de Preferência"
            aria-describedby="preference-notes-counter"
          />
          <div className={styles.footer}>
            <span id="preference-notes-counter" className={remaining < 100 ? styles.counterWarn : styles.counter} aria-live="polite">
              {draft.length}/{maxLength}
            </span>
            <div className={styles.actions}>
              <Button variant="secondary" onClick={() => setIsEditing(false)} disabled={isSaving}>
                Cancelar
              </Button>
              <Button onClick={handleSave} disabled={isSaving}>
                {isSaving ? <Spinner /> : 'Salvar'}
              </Button>
            </div>
          </div>
          {error && (
            <p className={styles.error} role="alert">
              {error}
            </p>
          )}
        </div>
      )}
    </section>
  );
}
