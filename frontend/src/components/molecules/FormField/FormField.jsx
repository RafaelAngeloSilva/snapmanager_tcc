import Input from '../../atoms/Input';
import styles from './FormField.module.css';

export default function FormField({ label, id, error, as: Field = Input, ...fieldProps }) {
  return (
    <label className={styles.field} htmlFor={id}>
      <span className={styles.label}>{label}</span>
      <Field id={id} invalid={Boolean(error)} {...fieldProps} />
      {error && <span className={styles.errorText}>{error}</span>}
    </label>
  );
}
