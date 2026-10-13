// Espelha a matriz de autorização real, aplicada no back-end via middleware
// de role nas rotas (routes/api.php). Isso aqui é só UX — esconder o que a
// pessoa não pode fazer evita cliques que terminam em 403 — nunca é a
// camada de segurança. Mesmo que este arquivo divirja do back-end por
// engano, o Laravel continua bloqueando a ação.
export const ROLE_LABELS = {
  admin: 'Administrador',
  attendant: 'Atendimento',
  photographer: 'Fotógrafo',
  editor: 'Editor',
};

const PERMISSIONS = {
  'dashboard.view': ['admin', 'attendant'],
  'clients.read': ['admin', 'attendant', 'photographer'],
  'clients.manage': ['admin', 'attendant'],
  // Histórico do cliente (Notas de Preferência e anotações): quem vê
  // clientes, inclusive o fotógrafo. Editar/excluir uma anotação depende do
  // autor — vem pronto do back-end em entry.can_edit.
  'clients.history': ['admin', 'attendant', 'photographer'],
  'appointments.read': ['admin', 'attendant', 'photographer'],
  'appointments.manage': ['admin', 'attendant'],
  'orders.read': ['admin', 'attendant', 'photographer', 'editor'],
  'orders.manage': ['admin', 'attendant'],
  'orders.delete': ['admin'],
  // Acompanhe seu Pedido: ativar/revogar/enviar o link público do cliente.
  'orders.tracking': ['admin', 'attendant'],
  'orders.updateStatus': ['admin', 'attendant', 'photographer', 'editor'],
  'galleries.read': ['admin', 'attendant', 'photographer', 'editor'],
  'galleries.manage': ['admin'],
  // Quadro de produção: todos veem e todos podem registrar uma demanda
  // própria; só o admin monta a estrutura (colunas) e só admin/atendimento
  // levam agendamentos e pedidos para o quadro. A permissão por CARD
  // (mexer só no que é seu) não cabe aqui porque depende do registro — ver
  // canManageTask() em utils/kanban.js.
  'kanban.read': ['admin', 'attendant', 'photographer', 'editor'],
  'kanban.createTask': ['admin', 'attendant', 'photographer', 'editor'],
  'kanban.manageColumns': ['admin'],
  'kanban.import': ['admin', 'attendant'],
  // Documentos: os 4 perfis leem (o back-end ainda filtra QUAIS documentos
  // cada um vê); criar/enviar é do atendimento. O que é possível em cada
  // documento vem pronto do back-end em `document.abilities`.
  'documents.read': ['admin', 'attendant', 'photographer', 'editor'],
  'documents.manage': ['admin', 'attendant'],
};

export function roleCan(role, permission) {
  return PERMISSIONS[permission]?.includes(role) ?? false;
}

export function roleLabel(role) {
  return ROLE_LABELS[role] ?? role;
}
