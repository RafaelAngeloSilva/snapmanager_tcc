import styles from './Spinner.module.css';

export default function Spinner({ size = 'md', className = '' }) {
  const classes = [styles.spinner, styles[size] || styles.md, className].filter(Boolean).join(' ');

  return <span className={classes} role="status" aria-label="Carregando" />;
}
