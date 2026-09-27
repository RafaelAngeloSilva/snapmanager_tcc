import apiClient from './client';

export async function fetchUsers() {
  const response = await apiClient.get('/users');

  return response.data.data;
}
