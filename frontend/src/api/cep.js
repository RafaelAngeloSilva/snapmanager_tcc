import apiClient from './client';

export async function fetchAddressByCep(cep) {
  const digits = cep.replace(/\D/g, '');
  const response = await apiClient.get(`/cep/${digits}`);

  return response.data;
}
