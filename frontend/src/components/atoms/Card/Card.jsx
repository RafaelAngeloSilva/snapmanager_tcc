import styles from './Card.module.css';

const PADDING_CLASS = {
  none: 'paddingNone',
  sm: 'paddingSm',
  md: 'paddingMd',
  lg: 'paddingLg',
};

export default function Card({ children, padding = 'md', hoverable = false, className = '', ...rest }) {
  const classes = [
    styles.card,
    styles[PADDING_CLASS[padding]] || styles.paddingMd,
    hoverable ? styles.hoverable : '',
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <div className={classes} {...rest}>
      {children}
    </div>
  );
}
