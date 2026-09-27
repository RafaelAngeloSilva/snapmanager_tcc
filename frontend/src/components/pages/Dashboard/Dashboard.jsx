import { useCallback, useEffect, useState } from 'react';
import { fetchDashboardSummary } from '../../../api/dashboard';
import DashboardHero from '../../organisms/DashboardHero';
import DashboardSummary from '../../organisms/DashboardSummary';
import DashboardCharts from '../../organisms/DashboardCharts';
import AlertsPanel from '../../organisms/AlertsPanel';
import styles from './Dashboard.module.css';

export default function Dashboard() {
  const [summary, setSummary] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = useCallback(() => {
    setIsLoading(true);
    setError(null);

    fetchDashboardSummary()
      .then(setSummary)
      .catch(() => setError('Não foi possível carregar o resumo.'))
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Dashboard</h1>
        <p className="page-subtitle">Visão geral do estúdio, em tempo real.</p>
      </div>

      {/* Um único GET /dashboard/summary alimenta o hero e os cards abaixo —
          antes, cada organismo buscava por conta própria (2 requisições
          idênticas disparadas a cada visita ao Dashboard). */}
      <DashboardHero summary={summary} />

      <div className={styles.summarySection}>
        <DashboardSummary summary={summary} isLoading={isLoading} error={error} onRetry={load} />
      </div>

      <DashboardCharts />

      <AlertsPanel />
    </div>
  );
}
