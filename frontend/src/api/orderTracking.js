import axios from 'axios';
import apiClient from './client';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';

/**
 * "Acompanhe seu Pedido".
 *
 * Ativar/revogar usam o apiClient autenticado (equipe). A consulta pública
 * usa uma instância própria, SEM o interceptor que redireciona para /login —
 * quem abre o link é o cliente, não um usuário do sistema (mesmo motivo do
 * api/publicGallery.js).
 */
const publicClient = axios.create({
  baseURL: API_BASE_URL,
  headers: { Accept: 'application/json' },
});

/** URL pública que o cliente recebe. */
export function orderTrackingUrl(token) {
  return token ? `${window.location.origin}/acompanhar/${token}` : '';
}

export async function enableOrderTracking(orderId) {
  const response = await apiClient.post(`/orders/${orderId}/tracking`);

  return response.data.data;
}

export async function revokeOrderTracking(orderId) {
  const response = await apiClient.delete(`/orders/${orderId}/tracking`);

  return response.data.data;
}

/** `preview` = aberto pela equipe ("Visualizar como cliente"): não conta como visita. */
export async function fetchPublicOrderTracking(token, { preview = false } = {}) {
  const response = await publicClient.get(`/public/tracking/${token}`, {
    params: preview ? { preview: 1 } : undefined,
  });

  return response.data.data;
}
