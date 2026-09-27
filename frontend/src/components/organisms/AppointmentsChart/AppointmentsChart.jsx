import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import ChartCard from '../../molecules/ChartCard';
import { parseDueDateAsLocalNoon } from '../../../utils/formatters';
import styles from './AppointmentsChart.module.css';

const weekdayFormatter = new Intl.DateTimeFormat('pt-BR', { weekday: 'short' });

function formatDayLabel(isoDate, index) {
  if (index === 0) return 'Hoje';

  const label = weekdayFormatter.format(parseDueDateAsLocalNoon(isoDate));

  return label.charAt(0).toUpperCase() + label.slice(1, 3).replace('.', '');
}

function CustomTooltip({ active, payload }) {
  if (!active || !payload?.length) {
    return null;
  }

  const { count, dayLabel } = payload[0].payload;

  return (
    <div className={styles.tooltip}>
      <strong>{dayLabel}</strong>
      <span>
        {count} agendamento{count === 1 ? '' : 's'}
      </span>
    </div>
  );
}

export default function AppointmentsChart({ data, isLoading, error, onRetry }) {
  const isEmpty = !isLoading && !error && (!data || data.every((item) => item.count === 0));

  const chartData = (data || []).map((item, index) => ({
    ...item,
    dayLabel: formatDayLabel(item.date, index),
  }));

  return (
    <ChartCard
      title="Agendamentos — próximos 7 dias"
      subtitle="Sessões confirmadas ou agendadas, por dia"
      isLoading={isLoading}
      error={error}
      onRetry={onRetry}
      isEmpty={isEmpty}
      emptyMessage="Nenhum agendamento nos próximos 7 dias."
    >
      <ResponsiveContainer width="100%" height={260}>
        <BarChart data={chartData} margin={{ top: 8, right: 8, left: -16, bottom: 0 }} barCategoryGap="30%">
          <XAxis
            dataKey="dayLabel"
            tick={{ fill: 'var(--color-text-muted)', fontSize: 11 }}
            axisLine={{ stroke: 'var(--color-border)' }}
            tickLine={false}
          />
          <YAxis
            allowDecimals={false}
            tick={{ fill: 'var(--color-text-muted)', fontSize: 11 }}
            axisLine={false}
            tickLine={false}
            width={28}
          />
          <Tooltip content={<CustomTooltip />} cursor={{ fill: 'var(--color-surface-alt)' }} />
          <Bar dataKey="count" radius={[4, 4, 0, 0]} maxBarSize={40} fill="var(--color-accent)" />
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}
