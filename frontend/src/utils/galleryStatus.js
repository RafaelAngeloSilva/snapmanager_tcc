export const GALLERY_STATUSES = [
  { value: 'active', label: 'Ativa' },
  { value: 'expired', label: 'Expirada' },
  { value: 'closed', label: 'Encerrada' },
];

export const GALLERY_STATUS_LABELS = GALLERY_STATUSES.reduce((acc, status) => {
  acc[status.value] = status.label;
  return acc;
}, {});

export const GALLERY_STATUS_BADGE_VARIANT = {
  active: 'success',
  expired: 'high',
  closed: 'neutral',
};

export const SELECTION_STATUS_LABELS = {
  pending: 'Aguardando escolha',
  completed: 'Escolha concluída',
};

export const SELECTION_STATUS_BADGE_VARIANT = {
  pending: 'medium',
  completed: 'success',
};

/**
 * "Concluída" só significa que o cliente já confirmou pelo menos uma vez —
 * ele ainda pode voltar e mudar a seleção enquanto a galeria estiver ativa
 * (selection_completed_at avança para a confirmação mais recente).
 */
export function getSelectionStatus(gallery) {
  return gallery?.selection_completed_at ? 'completed' : 'pending';
}
