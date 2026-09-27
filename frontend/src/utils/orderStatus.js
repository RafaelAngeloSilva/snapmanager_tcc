import { parseDueDateAsLocalNoon } from './formatters';

export const ORDER_STATUSES = [
  { value: 'pending', label: 'Pendente' },
  { value: 'captured', label: 'Capturado' },
  { value: 'in_editing', label: 'Em edição' },
  { value: 'in_review', label: 'Em revisão' },
  { value: 'ready_for_delivery', label: 'Pronto para entrega' },
  { value: 'delivered', label: 'Entregue' },
  { value: 'cancelled', label: 'Cancelado' },
];

export const ORDER_STATUS_LABELS = ORDER_STATUSES.reduce((acc, status) => {
  acc[status.value] = status.label;
  return acc;
}, {});

// "info" e "primary" são dois tons de azul bem parecidos — evitamos usá-los
// em estágios adjacentes do pipeline (captured → in_editing) pra não gerar
// dois badges quase idênticos lado a lado. "medium" se repete em in_editing
// e ready_for_delivery, mas com in_review (info) entre os dois na sequência.
export const ORDER_STATUS_BADGE_VARIANT = {
  pending: 'neutral',
  captured: 'primary',
  in_editing: 'medium',
  in_review: 'info',
  ready_for_delivery: 'medium',
  delivered: 'success',
  cancelled: 'high',
};

const NON_OVERDUE_STATUSES = ['delivered', 'cancelled'];

/**
 * Mesma regra usada no backend (DashboardController::alerts): atrasado é
 * due_date anterior a hoje e status diferente de delivered/cancelled.
 */
export function isOrderOverdue(order) {
  if (!order.due_date || NON_OVERDUE_STATUSES.includes(order.status)) {
    return false;
  }

  const due = parseDueDateAsLocalNoon(order.due_date);
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  return due < today;
}
