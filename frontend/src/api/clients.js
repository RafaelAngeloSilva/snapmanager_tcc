import apiClient from './client';

export async function fetchClients({ page = 1, search = '', signal } = {}) {
  const params = { page };

  if (search) {
    params.search = search;
  }

  const response = await apiClient.get('/clients', { params, signal });

  return response.data;
}

export async function fetchClient(id) {
  const response = await apiClient.get(`/clients/${id}`);

  return response.data.data;
}

export async function createClient(payload) {
  const response = await apiClient.post('/clients', payload);

  return response.data.data;
}

export async function updateClient(id, payload) {
  const response = await apiClient.put(`/clients/${id}`, payload);

  return response.data.data;
}

export async function deleteClient(id) {
  await apiClient.delete(`/clients/${id}`);
}
