import apiClient from './client';

export async function login(email, password) {
  const response = await apiClient.post('/login', { email, password });

  return response.data;
}

export async function register(payload) {
  const response = await apiClient.post('/register', payload);

  return response.data;
}

export async function forgotPassword(email) {
  const response = await apiClient.post('/forgot-password', { email });

  return response.data;
}

export async function resetPassword(payload) {
  const response = await apiClient.post('/reset-password', payload);

  return response.data;
}

export async function fetchGoogleAuthStatus() {
  const response = await apiClient.get('/auth/google/status');

  return response.data;
}

/** Troca o código de uso único do login com Google (+ nonce desta aba) por um token. */
export async function exchangeGoogleCode(code, nonce) {
  const response = await apiClient.post('/auth/google/exchange', { code, nonce });

  return response.data;
}

/** O cadastro público pode estar desativado no back-end (REGISTRATION_ENABLED). */
export async function fetchRegistrationStatus() {
  const response = await apiClient.get('/auth/registration/status');

  return response.data;
}

export async function logout() {
  await apiClient.post('/logout');
}

export async function fetchMe() {
  const response = await apiClient.get('/me');

  return response.data;
}
