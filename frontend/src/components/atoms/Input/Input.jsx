import styles from './Input.module.css';

export default function Input({ invalid = false, className = '', ...rest }) {
  const classes = [styles.input, invalid ? styles.invalid : '', className].filter(Boolean).join(' ');

  return <input className={classes} {...rest} />;
}
