import { useCallback, useEffect, useState } from 'react';
import { fetchDashboardAlerts } from '../../../api/dashboard';
import Card from '../../atoms/Card';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import Button from '../../atoms/Button';
import AlertItem from '../../molecules/AlertItem';
import styles from './AlertsPanel.module.css';

export default function AlertsPanel() {
  const [alerts, setAlerts] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = useCallback(() => {
    setIsLoading(true);
    setError(null);

    fetchDashboardAlerts()
      .then(setAlerts)
      .catch(() => setError('Não foi possível carregar os alertas.'))
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <Card padding="lg" className={styles.panel}>
      <h2 className="section-title">Alertas operacionais</h2>

      {isLoading && (
        <div className={styles.loadingBox}>
          <Spinner /> Carregando alertas...
        </div>
      )}

      {!isLoading && error && (
        <EmptyState
          tone="error"
          layout="row"
          title="Erro ao carregar alertas"
          message={error}
          action={
            <Button variant="danger" onClick={load}>
              Tentar novamente
            </Button>
          }
        />
      )}

      {!isLoading && !error && alerts.length === 0 && (
        <EmptyState title="Tudo em dia" message="Nenhum alerta operacional no momento." />
      )}

      {!isLoading && !error && alerts.length > 0 && (
        <ul className={styles.list}>
          {alerts.map((alert) => (
            <AlertItem key={`${alert.type}-${alert.entity_id}-${alert.date}`} alert={alert} />
          ))}
        </ul>
      )}
    </Card>
  );
}
