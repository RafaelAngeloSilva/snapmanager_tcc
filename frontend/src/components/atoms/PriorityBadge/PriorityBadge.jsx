import Badge from '../Badge';
import { PRIORITY_BADGE_VARIANT, PRIORITY_LABELS } from '../../../utils/kanban';

export default function PriorityBadge({ priority }) {
  if (!priority) return null;

  return (
    <Badge variant={PRIORITY_BADGE_VARIANT[priority] || 'neutral'}>
      {PRIORITY_LABELS[priority] || priority}
    </Badge>
  );
}
