import apiClient from './client';

export async function fetchOrders({ page = 1, status = '', clientId = '', dueDate = '' } = {}) {
  const params = { page };

  if (status) params.status = status;
  if (clientId) params.client_id = clientId;
  if (dueDate) params.due_date = dueDate;

  const response = await apiClient.get('/orders', { params });

  return response.data;
}

export async function fetchOrder(id) {
  const response = await apiClient.get(`/orders/${id}`);

  return response.data.data;
}

export async function createOrder(payload) {
  const response = await apiClient.post('/orders', payload);

  return response.data.data;
}

export async function updateOrder(id, payload) {
  const response = await apiClient.put(`/orders/${id}`, payload);

  return response.data.data;
}

// { message, data } — a mensagem importa quando o status enviado é igual ao
// atual (backend não duplica histórico e avisa que nada mudou).
export async function updateOrderStatus(id, payload) {
  const response = await apiClient.patch(`/orders/${id}/status`, payload);

  return response.data;
}

export async function deleteOrder(id) {
  await apiClient.delete(`/orders/${id}`);
}
