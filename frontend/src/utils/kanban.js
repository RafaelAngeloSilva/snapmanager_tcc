// Labels e variantes visuais do quadro de produção. Mesmo padrão dos outros
// utilitários de status (orderStatus.js, galleryStatus.js): o back-end
// manda o código, a UI traduz.

export const KANBAN_PRIORITIES = [
  { value: 'low', label: 'Baixa' },
  { value: 'medium', label: 'Média' },
  { value: 'high', label: 'Alta' },
  { value: 'urgent', label: 'Urgente' },
];

export const PRIORITY_LABELS = KANBAN_PRIORITIES.reduce((acc, priority) => {
  acc[priority.value] = priority.label;
  return acc;
}, {});

export const PRIORITY_BADGE_VARIANT = {
  low: 'neutral',
  medium: 'info',
  high: 'medium',
  urgent: 'high',
};

export const KANBAN_ORIGINS = [
  { value: 'appointment', label: 'Agendamento' },
  { value: 'order', label: 'Pedido' },
  { value: 'manual', label: 'Manual' },
];

export const ORIGIN_LABELS = KANBAN_ORIGINS.reduce((acc, origin) => {
  acc[origin.value] = origin.label;
  return acc;
}, {});

export const ORIGIN_BADGE_VARIANT = {
  appointment: 'primary',
  order: 'info',
  manual: 'neutral',
};

/**
 * Espelha KanbanTaskPolicy no back-end: quem administra o quadro mexe em
 * qualquer card; os demais, apenas nos cards atribuídos a eles. Serve só
 * para esconder botões — o Laravel continua respondendo 403 se alguém
 * tentar pela API.
 */
export function canManageTask(user, task) {
  if (!user || !task) return false;

  if (user.role === 'admin' || user.role === 'attendant') {
    return true;
  }

  return task.assignee?.id === user.id;
}
