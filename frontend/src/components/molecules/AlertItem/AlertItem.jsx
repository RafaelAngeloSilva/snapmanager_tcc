import Badge from '../../atoms/Badge';
import { WarningIcon, ClockIcon, CheckCircleIcon, CalendarIcon, EyeIcon } from '../../atoms/Icon';
import styles from './AlertItem.module.css';

const SEVERITY_LABELS = {
  high: 'Alta',
  medium: 'Média',
  info: 'Info',
};

const TYPE_ICONS = {
  overdue_order: WarningIcon,
  due_soon_order: ClockIcon,
  ready_for_delivery: CheckCircleIcon,
  appointment_today: CalendarIcon,
  // Acompanhe seu Pedido: cliente abriu o link de acompanhamento.
  tracking_viewed: EyeIcon,
};

export default function AlertItem({ alert }) {
  const severityLabel = SEVERITY_LABELS[alert.severity] || alert.severity;
  const severityClass = styles[alert.severity] || '';
  const AlertIcon = TYPE_ICONS[alert.type] || WarningIcon;

  return (
    <li className={`${styles.item} ${severityClass}`}>
      <span className={styles.iconWrap}>
        <AlertIcon width={17} height={17} />
      </span>

      <div className={styles.body}>
        <div className={styles.header}>
          <span className={styles.title}>{alert.title}</span>
          <Badge variant={alert.severity} dot={false}>
            {severityLabel}
          </Badge>
          {alert.date && <span className={styles.date}>{alert.date}</span>}
        </div>
        <p className={styles.message}>{alert.message}</p>
      </div>
    </li>
  );
}
