import DashboardReportActions from '../../molecules/DashboardReportActions';
import styles from './DashboardHero.module.css';

function buildMessage(summary) {
  if (!summary) {
    return 'Carregando a visão do seu dia...';
  }

  const appointmentsToday = summary.appointments.appointments_today;
  const overdueOrders = summary.orders.overdue_orders;
  const awaitingGalleries = summary.galleries.awaiting_selection_galleries;

  return (
    `Hoje você tem ${appointmentsToday} agendamento(s), ` +
    `${overdueOrders} pedido(s) atrasado(s) e ` +
    `${awaitingGalleries} galeria(s) aguardando escolha.`
  );
}

// Recebe "summary" do Dashboard (pai) em vez de buscar por conta própria —
// evita uma segunda requisição idêntica à que o DashboardSummary já faz.
export default function DashboardHero({ summary }) {
  return (
    <div className={styles.hero}>
      <div>
        <p className={styles.eyebrow}>Visão do dia</p>
        <h2 className={styles.message}>{buildMessage(summary)}</h2>
      </div>

      <DashboardReportActions />
    </div>
  );
}
