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

export async function logout() {
  await apiClient.post('/logout');
}

export async function fetchMe() {
  const response = await apiClient.get('/me');

  return response.data;
}
