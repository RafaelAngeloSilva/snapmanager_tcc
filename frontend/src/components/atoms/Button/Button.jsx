import styles from './Button.module.css';

export default function Button({
  variant = 'primary',
  size = 'md',
  fullWidth = false,
  isLoading = false,
  className = '',
  children,
  disabled,
  type = 'button',
  ...rest
}) {
  const classes = [
    styles.button,
    styles[variant],
    size === 'icon' ? styles.icon : '',
    fullWidth ? styles.fullWidth : '',
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <button type={type} className={classes} disabled={disabled || isLoading} {...rest}>
      {children}
    </button>
  );
}
