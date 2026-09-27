import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import Button from '../../atoms/Button';
import SummaryCard from '../../molecules/SummaryCard';
import { CalendarIcon, WarningIcon, WalletIcon, UsersIcon, PackageIcon } from '../../atoms/Icon';
import styles from './DashboardSummary.module.css';

const currencyFormatter = new Intl.NumberFormat('pt-BR', {
  style: 'currency',
  currency: 'BRL',
});

// "summary"/"isLoading"/"error" vêm do Dashboard (pai) — antes este
// componente buscava /dashboard/summary por conta própria, ao mesmo tempo
// que o DashboardHero fazia a mesma requisição; agora só existe uma busca,
// compartilhada pelos dois.
export default function DashboardSummary({ summary, isLoading, error, onRetry }) {
  if (isLoading) {
    return (
      <div className={styles.loadingBox}>
        <Spinner /> Carregando resumo...
      </div>
    );
  }

  if (error) {
    return (
      <EmptyState
        tone="error"
        layout="row"
        title="Erro ao carregar o resumo"
        message={error}
        action={
          <Button variant="danger" onClick={onRetry}>
            Tentar novamente
          </Button>
        }
      />
    );
  }

  if (!summary) {
    return null;
  }

  return (
    <>
      {/* Resumo do dia — mesmos dados do payload de summary, só com mais
          destaque visual para os 3 números mais "acionáveis" agora. */}
      <div className={styles.heroStats}>
        <div className={styles.heroStat}>
          <span className={styles.heroIcon}>
            <CalendarIcon width={20} height={20} />
          </span>
          <div>
            <p className={styles.heroValue}>{summary.appointments.appointments_today}</p>
            <p className={styles.heroLabel}>Agendamentos hoje</p>
          </div>
        </div>

        <div className={styles.heroDivider} />

        <div className={styles.heroStat}>
          <span className={`${styles.heroIcon} ${styles.heroIconWarn}`}>
            <WarningIcon width={20} height={20} />
          </span>
          <div>
            <p className={styles.heroValue}>{summary.orders.overdue_orders}</p>
            <p className={styles.heroLabel}>Pedidos atrasados</p>
          </div>
        </div>

        <div className={styles.heroDivider} />

        <div className={styles.heroStat}>
          <span className={`${styles.heroIcon} ${styles.heroIconAccent}`}>
            <WalletIcon width={20} height={20} />
          </span>
          <div>
            <p className={styles.heroValue}>{currencyFormatter.format(summary.financial.total_revenue_delivered)}</p>
            <p className={styles.heroLabel}>Receita entregue</p>
          </div>
        </div>
      </div>

      <div className={styles.grid}>
        <SummaryCard
          title="Clientes"
          icon={<UsersIcon width={17} height={17} />}
          stats={[{ label: 'Total de clientes', value: summary.clients.total_clients }]}
        />

        <SummaryCard
          title="Agendamentos"
          icon={<CalendarIcon width={17} height={17} />}
          stats={[
            { label: 'Total', value: summary.appointments.total_appointments },
            { label: 'Hoje', value: summary.appointments.appointments_today, highlight: true },
            { label: 'Próximos', value: summary.appointments.upcoming_appointments },
            { label: 'Cancelados', value: summary.appointments.cancelled_appointments },
          ]}
        />

        <SummaryCard
          title="Pedidos"
          icon={<PackageIcon width={17} height={17} />}
          stats={[
            { label: 'Total', value: summary.orders.total_orders },
            { label: 'Pendentes', value: summary.orders.pending_orders },
            { label: 'Capturados', value: summary.orders.captured_orders },
            { label: 'Em edição', value: summary.orders.in_editing_orders },
            { label: 'Em revisão', value: summary.orders.in_review_orders },
            { label: 'Prontos p/ entrega', value: summary.orders.ready_for_delivery_orders },
            { label: 'Entregues', value: summary.orders.delivered_orders },
            { label: 'Cancelados', value: summary.orders.cancelled_orders },
            { label: 'Atrasados', value: summary.orders.overdue_orders, highlight: summary.orders.overdue_orders > 0 },
          ]}
        />

        <SummaryCard
          title="Financeiro"
          icon={<WalletIcon width={17} height={17} />}
          stats={[
            {
              label: 'Receita entregue',
              value: currencyFormatter.format(summary.financial.total_revenue_delivered),
              highlight: true,
            },
            {
              label: 'Receita pendente',
              value: currencyFormatter.format(summary.financial.total_revenue_pending),
            },
          ]}
        />
      </div>
    </>
  );
}
