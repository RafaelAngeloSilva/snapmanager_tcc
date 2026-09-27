import styles from './Select.module.css';

export default function Select({ invalid = false, className = '', children, ...rest }) {
  const classes = [styles.select, invalid ? styles.invalid : '', className].filter(Boolean).join(' ');

  return (
    <select className={classes} {...rest}>
      {children}
    </select>
  );
}
