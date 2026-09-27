import { CameraIcon } from '../Icon';
import styles from './EmptyState.module.css';

export default function EmptyState({ tone = 'neutral', layout = 'column', title, message, action }) {
  const showIcon = layout === 'column' && tone === 'neutral';
  const classes = [styles.emptyState, styles[tone] || styles.neutral, layout === 'row' ? styles.row : '']
    .filter(Boolean)
    .join(' ');

  return (
    <div className={classes}>
      {showIcon && (
        <span className={styles.iconWrap} aria-hidden="true">
          <CameraIcon width={22} height={22} />
        </span>
      )}
      <div>
        {title && <p className={styles.title}>{title}</p>}
        {message && <p className={styles.message}>{message}</p>}
      </div>
      {action && <div className={styles.action}>{action}</div>}
    </div>
  );
}
