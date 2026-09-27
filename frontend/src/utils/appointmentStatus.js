export const APPOINTMENT_STATUSES = [
  { value: 'scheduled', label: 'Agendado' },
  { value: 'confirmed', label: 'Confirmado' },
  { value: 'completed', label: 'Concluído' },
  { value: 'cancelled', label: 'Cancelado' },
];

export const APPOINTMENT_STATUS_LABELS = APPOINTMENT_STATUSES.reduce((acc, status) => {
  acc[status.value] = status.label;
  return acc;
}, {});

export const APPOINTMENT_STATUS_BADGE_VARIANT = {
  scheduled: 'neutral',
  confirmed: 'info',
  completed: 'success',
  cancelled: 'high',
};
