import { Bar, BarChart, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import ChartCard from '../../molecules/ChartCard';
import { ORDER_STATUS_BADGE_VARIANT } from '../../../utils/orderStatus';
import styles from './OrdersStatusChart.module.css';

// Mesmas cores já usadas nos badges de status da tabela de Pedidos (ver
// Badge.module.css) — o gráfico não introduz uma paleta nova, só reaproveita
// o que já existe, então continua consistente com o resto do sistema (e
// reage ao tema claro/escuro automaticamente, já que são variáveis CSS).
const VARIANT_COLOR = {
  neutral: 'var(--color-text-muted)',
  primary: 'var(--color-primary)',
  medium: 'var(--color-warning)',
  info: 'var(--color-info)',
  success: 'var(--color-success)',
  high: 'var(--color-danger)',
};

function CustomTooltip({ active, payload }) {
  if (!active || !payload?.length) {
    return null;
  }

  const { label, count } = payload[0].payload;

  return (
    <div className={styles.tooltip}>
      <strong>{label}</strong>
      <span>{count} pedido(s)</span>
    </div>
  );
}

export default function OrdersStatusChart({ data, isLoading, error, onRetry }) {
  const isEmpty = !isLoading && !error && (!data || data.every((item) => item.count === 0));

  return (
    <ChartCard
      title="Pedidos por status"
      subtitle="Volume atual em cada etapa da produção"
      isLoading={isLoading}
      error={error}
      onRetry={onRetry}
      isEmpty={isEmpty}
      emptyMessage="Ainda não há pedidos cadastrados."
    >
      <ResponsiveContainer width="100%" height={260}>
        <BarChart data={data} margin={{ top: 8, right: 8, left: -16, bottom: 0 }} barCategoryGap="28%">
          <XAxis
            dataKey="label"
            tick={{ fill: 'var(--color-text-muted)', fontSize: 11 }}
            axisLine={{ stroke: 'var(--color-border)' }}
            tickLine={false}
            interval={0}
            angle={-25}
            textAnchor="end"
            height={54}
          />
          <YAxis
            allowDecimals={false}
            tick={{ fill: 'var(--color-text-muted)', fontSize: 11 }}
            axisLine={false}
            tickLine={false}
            width={28}
          />
          <Tooltip content={<CustomTooltip />} cursor={{ fill: 'var(--color-surface-alt)' }} />
          <Bar dataKey="count" radius={[4, 4, 0, 0]} maxBarSize={40}>
            {(data || []).map((entry) => (
              <Cell key={entry.status} fill={VARIANT_COLOR[ORDER_STATUS_BADGE_VARIANT[entry.status]] || 'var(--color-primary)'} />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}
