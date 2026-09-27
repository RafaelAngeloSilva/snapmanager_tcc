import styles from './Badge.module.css';

export default function Badge({ variant = 'neutral', dot = true, children, className = '' }) {
  const classes = [styles.badge, styles[variant] || styles.neutral, className].filter(Boolean).join(' ');

  return (
    <span className={classes}>
      {dot && <span className={styles.dot} aria-hidden="true" />}
      {children}
    </span>
  );
}
