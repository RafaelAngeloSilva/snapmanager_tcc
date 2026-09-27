import apiClient from './client';

export async function fetchBoard(filters = {}) {
  const params = {};

  // Filtros vão para a query string — o back-end devolve só os cards que
  // interessam, em vez de o navegador baixar tudo e filtrar depois.
  if (filters.assignedTo) params.assigned_to = filters.assignedTo;
  if (filters.priority) params.priority = filters.priority;
  if (filters.origin) params.origin = filters.origin;
  if (filters.clientId) params.client_id = filters.clientId;

  const response = await apiClient.get('/kanban', { params });

  return response.data.data;
}

export async function createColumn(payload) {
  const response = await apiClient.post('/kanban/columns', payload);

  return response.data.data;
}

export async function updateColumn(id, payload) {
  const response = await apiClient.put(`/kanban/columns/${id}`, payload);

  return response.data.data;
}

export async function deleteColumn(id, { moveTasksTo } = {}) {
  await apiClient.delete(`/kanban/columns/${id}`, {
    data: moveTasksTo ? { move_tasks_to: moveTasksTo } : {},
  });
}

export async function reorderColumns(columnIds) {
  const response = await apiClient.patch('/kanban/columns/reorder', { columns: columnIds });

  return response.data.data;
}

export async function createTask(payload) {
  const response = await apiClient.post('/kanban/tasks', payload);

  return response.data.data;
}

export async function updateTask(id, payload) {
  const response = await apiClient.put(`/kanban/tasks/${id}`, payload);

  return response.data.data;
}

export async function deleteTask(id) {
  await apiClient.delete(`/kanban/tasks/${id}`);
}

export async function moveTask(id, { columnId, position }) {
  const response = await apiClient.patch(`/kanban/tasks/${id}/move`, {
    kanban_column_id: columnId,
    position,
  });

  return response.data.data;
}

export async function addAppointmentToBoard(appointmentId) {
  const response = await apiClient.post(`/appointments/${appointmentId}/kanban`);

  return response.data.data;
}

export async function addOrderToBoard(orderId) {
  const response = await apiClient.post(`/orders/${orderId}/kanban`);

  return response.data.data;
}
