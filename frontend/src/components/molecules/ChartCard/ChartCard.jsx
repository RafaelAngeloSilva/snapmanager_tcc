import Card from '../../atoms/Card';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import Button from '../../atoms/Button';
import styles from './ChartCard.module.css';

export default function ChartCard({ title, subtitle, isLoading, error, isEmpty, emptyMessage, onRetry, children }) {
  return (
    <Card padding="none" className={styles.card}>
      <div className={styles.head}>
        <h3 className={styles.title}>{title}</h3>
        {subtitle && <p className={styles.subtitle}>{subtitle}</p>}
      </div>

      <div className={styles.body}>
        {isLoading && (
          <div className={styles.stateBox}>
            <Spinner /> Carregando...
          </div>
        )}

        {!isLoading && error && (
          <EmptyState
            tone="error"
            layout="row"
            title="Erro ao carregar"
            message={error}
            action={
              onRetry && (
                <Button variant="danger" onClick={onRetry}>
                  Tentar novamente
                </Button>
              )
            }
          />
        )}

        {!isLoading && !error && isEmpty && (
          <EmptyState
            title="Sem dados"
            message={emptyMessage || 'Ainda não há dados suficientes para este gráfico.'}
          />
        )}

        {!isLoading && !error && !isEmpty && children}
      </div>
    </Card>
  );
}
