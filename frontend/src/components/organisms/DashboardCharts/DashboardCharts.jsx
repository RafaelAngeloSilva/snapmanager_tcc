import { useCallback, useEffect, useState } from 'react';
import { fetchDashboardCharts } from '../../../api/dashboard';
import OrdersStatusChart from '../OrdersStatusChart';
import RevenueChart from '../RevenueChart';
import AppointmentsChart from '../AppointmentsChart';
import styles from './DashboardCharts.module.css';

export default function DashboardCharts() {
  const [charts, setCharts] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    setIsLoading(true);
    setError('');

    // Uma única requisição agregada alimenta os 3 gráficos — o back-end já
    // faz todo o GROUP BY/COUNT/SUM; o React só desenha.
    fetchDashboardCharts()
      .then(setCharts)
      .catch(() => setError('Não foi possível carregar os gráficos agora.'))
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <div className={styles.grid}>
      <div className={styles.wide}>
        <RevenueChart data={charts?.revenue_by_period} isLoading={isLoading} error={error} onRetry={load} />
      </div>
      <OrdersStatusChart data={charts?.orders_by_status} isLoading={isLoading} error={error} onRetry={load} />
      <AppointmentsChart data={charts?.appointments_by_period} isLoading={isLoading} error={error} onRetry={load} />
    </div>
  );
}
