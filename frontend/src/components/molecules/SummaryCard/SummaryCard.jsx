import Card from '../../atoms/Card';
import styles from './SummaryCard.module.css';

export default function SummaryCard({ title, icon, stats }) {
  return (
    <Card padding="none" hoverable className={styles.card}>
      <div className={styles.head}>
        <h3 className={styles.title}>{title}</h3>
        {icon && <span className={styles.iconWrap}>{icon}</span>}
      </div>

      <dl className={styles.stats}>
        {stats.map((stat) => (
          <div key={stat.label} className={`${styles.stat} ${stat.highlight ? styles.highlight : ''}`}>
            <dt>{stat.label}</dt>
            <dd>{stat.value}</dd>
          </div>
        ))}
      </dl>
    </Card>
  );
}
