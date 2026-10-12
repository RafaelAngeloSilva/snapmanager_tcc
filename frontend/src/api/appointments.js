import apiClient from './client';

export async function fetchAppointments({ page = 1, clientId } = {}) {
  const params = { page };

  if (clientId) {
    params.client_id = clientId;
  }

  const response = await apiClient.get('/appointments', { params });

  return response.data;
}

export async function fetchAppointment(id) {
  const response = await apiClient.get(`/appointments/${id}`);

  return response.data.data;
}

export async function createAppointment(payload) {
  const response = await apiClient.post('/appointments', payload);

  return response.data.data;
}

export async function updateAppointment(id, payload) {
  const response = await apiClient.put(`/appointments/${id}`, payload);

  return response.data.data;
}

export async function deleteAppointment(id) {
  await apiClient.delete(`/appointments/${id}`);
}
