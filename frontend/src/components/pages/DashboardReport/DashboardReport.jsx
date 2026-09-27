import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../../../context/AuthContext';
import { fetchDashboardAlerts, fetchDashboardCharts, fetchDashboardSummary } from '../../../api/dashboard';
import { formatCurrency, formatDateTime } from '../../../utils/formatters';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import { ApertureIcon, ReportIcon } from '../../atoms/Icon';
import AlertItem from '../../molecules/AlertItem';
import OrdersStatusChart from '../../organisms/OrdersStatusChart';
import RevenueChart from '../../organisms/RevenueChart';
import AppointmentsChart from '../../organisms/AppointmentsChart';
import styles from './DashboardReport.module.css';

function StatTile({ label, value }) {
  return (
    <div className={styles.statTile}>
      <p className={styles.statValue}>{value}</p>
      <p className={styles.statLabel}>{label}</p>
    </div>
  );
}

export default function DashboardReport() {
  const { user } = useAuth();
  const [summary, setSummary] = useState(null);
  const [charts, setCharts] = useState(null);
  const [alerts, setAlerts] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState('');

  // Fixado no momento em que a página é aberta — não muda a cada
  // re-render, e é exatamente o "emitido em" mostrado no cabeçalho/rodapé.
  const issuedAt = useMemo(() => new Date(), []);

  useEffect(() => {
    setIsLoading(true);
    setError('');

    // Mesma fonte de dados do Dashboard (os 3 endpoints que ele já usa) —
    // nada é recalculado aqui, só reorganizado visualmente para impressão.
    Promise.all([fetchDashboardSummary(), fetchDashboardCharts(), fetchDashboardAlerts()])
      .then(([summaryData, chartsData, alertsData]) => {
        setSummary(summaryData);
        setCharts(chartsData);
        setAlerts(alertsData);
      })
      .catch(() => setError('Não foi possível carregar os dados do relatório.'))
      .finally(() => setIsLoading(false));
  }, []);

  if (isLoading) {
    return (
      <div className={styles.loadingBox}>
        <Spinner /> Carregando relatório...
      </div>
    );
  }

  if (error) {
    return <EmptyState tone="error" title="Erro ao carregar o relatório" message={error} />;
  }

  return (
    <div className={styles.page}>
      <div className={styles.actionsBar}>
        <Button onClick={() => window.print()}>
          <ReportIcon width={16} height={16} />
          Imprimir / Salvar em PDF
        </Button>
      </div>

      <div className={styles.report}>
        <header className={styles.reportHeader}>
          <div className={styles.brandMark}>
            <span className={styles.brandIcon}>
              <ApertureIcon width={15} height={15} />
            </span>
            <span className={styles.brandWordmark}>
              SnapManager<span className={styles.brandDot} />
            </span>
          </div>

          <h1 className={styles.reportTitle}>Relatório Operacional</h1>

          <div className={styles.metaRow}>
            <div>
              <span className={styles.metaLabel}>Período</span>
              <p className={styles.metaValue}>Dados atuais do sistema</p>
            </div>
            <div>
              <span className={styles.metaLabel}>Emitido por</span>
              <p className={styles.metaValue}>{user?.name}</p>
            </div>
            <div>
              <span className={styles.metaLabel}>Emitido em</span>
              <p className={styles.metaValue}>{formatDateTime(issuedAt)}</p>
            </div>
          </div>
        </header>

        <section className={styles.section}>
          <h2 className={styles.sectionTitle}>Resumo</h2>
          <div className={styles.statsGrid}>
            <StatTile label="Clientes" value={summary.clients.total_clients} />
            <StatTile label="Agendamentos hoje" value={summary.appointments.appointments_today} />
            <StatTile label="Pedidos atrasados" value={summary.orders.overdue_orders} />
            <StatTile label="Receita entregue" value={formatCurrency(summary.financial.total_revenue_delivered)} />
            <StatTile label="Receita pendente" value={formatCurrency(summary.financial.total_revenue_pending)} />
            <StatTile label="Galerias aguardando escolha" value={summary.galleries.awaiting_selection_galleries} />
          </div>
        </section>

        <section className={styles.section}>
          <OrdersStatusChart data={charts.orders_by_status} isLoading={false} error="" />
        </section>

        <section className={styles.section}>
          <RevenueChart data={charts.revenue_by_period} isLoading={false} error="" />
        </section>

        <section className={styles.section}>
          <AppointmentsChart data={charts.appointments_by_period} isLoading={false} error="" />
        </section>

        <section className={styles.section}>
          <h2 className={styles.sectionTitle}>Alertas operacionais</h2>
          {alerts.length === 0 ? (
            <p className={styles.emptyText}>Nenhum alerta ativo no momento.</p>
          ) : (
            <ul className={styles.alertsList}>
              {alerts.map((alert) => (
                <AlertItem key={`${alert.type}-${alert.entity_id}-${alert.date}`} alert={alert} />
              ))}
            </ul>
          )}
        </section>

        <section className={styles.section}>
          <h2 className={styles.sectionTitle}>Galerias</h2>
          <dl className={styles.galleriesGrid}>
            <div className={styles.galleryStat}>
              <dt>Ativas</dt>
              <dd>{summary.galleries.active_galleries}</dd>
            </div>
            <div className={styles.galleryStat}>
              <dt>Concluídas</dt>
              <dd>{summary.galleries.completed_selection_galleries}</dd>
            </div>
            <div className={styles.galleryStat}>
              <dt>Aguardando escolha</dt>
              <dd>{summary.galleries.awaiting_selection_galleries}</dd>
            </div>
            <div className={styles.galleryStat}>
              <dt>Expiradas</dt>
              <dd>{summary.galleries.expired_galleries}</dd>
            </div>
          </dl>
        </section>

        <footer className={styles.reportFooter}>
          <p>SnapManager — Gestão para estúdios fotográficos</p>
          <p>Emitido em {formatDateTime(issuedAt)}</p>
        </footer>
      </div>
    </div>
  );
}
