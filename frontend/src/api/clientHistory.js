import apiClient from './client';

/** Resumo, selo, Notas de Preferência e linha do tempo do cliente. */
export async function fetchClientHistory(clientId) {
  const response = await apiClient.get(`/clients/${clientId}/history`);

  return response.data.data;
}

export async function updatePreferenceNotes(clientId, preferenceNotes) {
  const response = await apiClient.put(`/clients/${clientId}/preferences`, { preference_notes: preferenceNotes });

  return response.data.data.preference_notes;
}

export async function createHistoryEntry(clientId, payload) {
  const response = await apiClient.post(`/clients/${clientId}/history-entries`, payload);

  return response.data.data;
}

export async function updateHistoryEntry(clientId, entryId, payload) {
  const response = await apiClient.put(`/clients/${clientId}/history-entries/${entryId}`, payload);

  return response.data.data;
}

export async function deleteHistoryEntry(clientId, entryId) {
  await apiClient.delete(`/clients/${clientId}/history-entries/${entryId}`);
}
