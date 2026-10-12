import Input from '../../atoms/Input';
import { COUNTER_THRESHOLD } from '../../../utils/fieldLimits';
import styles from './FormField.module.css';

/**
 * Campo de formulário com rótulo e mensagem de erro.
 *
 * Com `maxLength` (em campos de texto), mostra um contador "n/máx":
 * sempre nos campos longos (observações, descrições) e, nos curtos, só
 * quando o texto chega perto do limite — para o usuário saber POR QUE não
 * consegue digitar mais. O limite real é validado pelo back-end.
 */
export default function FormField({ label, id, error, as: Field = Input, ...fieldProps }) {
  const { maxLength, value } = fieldProps;
  const length = typeof value === 'string' ? value.length : 0;
  const showCounter =
    Number.isInteger(maxLength) && (maxLength >= COUNTER_THRESHOLD ? true : length >= Math.floor(maxLength * 0.8));
  const atLimit = showCounter && length >= maxLength;
  const counterId = showCounter ? `${id}-counter` : undefined;

  return (
    // O <label> envolve só o texto (htmlFor liga ao campo): campos com botão
    // interno (ex.: mostrar senha) não podem ficar dentro de um <label>.
    <div className={styles.field}>
      <label className={styles.label} htmlFor={id}>
        {label}
      </label>
      <Field id={id} invalid={Boolean(error)} aria-describedby={counterId} {...fieldProps} />
      {(error || showCounter) && (
        <span className={styles.footer}>
          {error ? <span className={styles.errorText}>{error}</span> : <span />}
          {showCounter && (
            <span id={counterId} className={atLimit ? styles.counterLimit : styles.counter} aria-live="polite">
              {length}/{maxLength}
              {atLimit && ' — limite atingido'}
            </span>
          )}
        </span>
      )}
    </div>
  );
}
