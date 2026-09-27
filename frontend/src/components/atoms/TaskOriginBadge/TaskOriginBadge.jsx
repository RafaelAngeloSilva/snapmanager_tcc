import Badge from '../Badge';
import { ORIGIN_BADGE_VARIANT, ORIGIN_LABELS } from '../../../utils/kanban';

/**
 * Identifica visualmente de onde o card nasceu: agendamento, pedido ou
 * demanda avulsa.
 */
export default function TaskOriginBadge({ origin }) {
  if (!origin) return null;

  return (
    <Badge variant={ORIGIN_BADGE_VARIANT[origin] || 'neutral'} dot={false}>
      {ORIGIN_LABELS[origin] || origin}
    </Badge>
  );
}
