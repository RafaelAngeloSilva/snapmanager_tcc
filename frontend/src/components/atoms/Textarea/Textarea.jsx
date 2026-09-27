import styles from './Textarea.module.css';

export default function Textarea({ invalid = false, rows = 3, className = '', ...rest }) {
  const classes = [styles.textarea, invalid ? styles.invalid : '', className].filter(Boolean).join(' ');

  return <textarea className={classes} rows={rows} {...rest} />;
}
