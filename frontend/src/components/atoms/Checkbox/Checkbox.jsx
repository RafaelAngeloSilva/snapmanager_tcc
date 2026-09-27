import styles from './Checkbox.module.css';

export default function Checkbox({ checked, onChange, className = '', ...rest }) {
  return (
    <span className={[styles.wrapper, checked ? styles.checked : '', className].filter(Boolean).join(' ')}>
      <input type="checkbox" checked={checked} onChange={onChange} className={styles.input} {...rest} />
      <svg viewBox="0 0 24 24" width={14} height={14} className={styles.check} aria-hidden="true">
        <path d="m5 12.5 4.5 4.5L19 7" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    </span>
  );
}
