import { Area, AreaChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import ChartCard from '../../molecules/ChartCard';
import { formatCurrency, parseDueDateAsLocalNoon } from '../../../utils/formatters';
import styles from './RevenueChart.module.css';

const shortDateFormatter = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' });

function formatShortDate(isoDate) {
  return shortDateFormatter.format(parseDueDateAsLocalNoon(isoDate));
}

function CustomTooltip({ active, payload }) {
  if (!active || !payload?.length) {
    return null;
  }

  const { date, total } = payload[0].payload;

  return (
    <div className={styles.tooltip}>
      <strong>{formatShortDate(date)}</strong>
      <span>{formatCurrency(total)}</span>
    </div>
  );
}

export default function RevenueChart({ data, isLoading, error, onRetry }) {
  const isEmpty = !isLoading && !error && (!data || data.every((item) => item.total === 0));

  const chartData = (data || []).map((item) => ({ ...item, shortDate: formatShortDate(item.date) }));

  return (
    <ChartCard
      title="Faturamento — últimos 30 dias"
      subtitle="Soma dos pedidos entregues, por dia"
      isLoading={isLoading}
      error={error}
      onRetry={onRetry}
      isEmpty={isEmpty}
      emptyMessage="Nenhum pedido entregue nos últimos 30 dias."
    >
      <ResponsiveContainer width="100%" height={260}>
        <AreaChart data={chartData} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
          <defs>
            <linearGradient id="revenueFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--color-primary)" stopOpacity={0.28} />
              <stop offset="100%" stopColor="var(--color-primary)" stopOpacity={0.02} />
            </linearGradient>
          </defs>
          <XAxis
            dataKey="shortDate"
            tick={{ fill: 'var(--color-text-muted)', fontSize: 11 }}
            axisLine={{ stroke: 'var(--color-border)' }}
            tickLine={false}
            interval={4}
          />
          <YAxis
            tick={{ fill: 'var(--color-text-muted)', fontSize: 11 }}
            axisLine={false}
            tickLine={false}
            width={48}
            tickFormatter={(value) => value.toLocaleString('pt-BR')}
          />
          <Tooltip content={<CustomTooltip />} cursor={{ stroke: 'var(--color-border-strong)', strokeWidth: 1 }} />
          <Area
            type="monotone"
            dataKey="total"
            stroke="var(--color-primary)"
            strokeWidth={2}
            fill="url(#revenueFill)"
          />
        </AreaChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}
