import apiClient from './client';

export async function fetchProfile() {
  const response = await apiClient.get('/profile');

  return response.data;
}

export async function updateProfile({ name }) {
  const response = await apiClient.put('/profile', { name });

  return response.data;
}

export async function updateProfilePassword({ currentPassword, password, passwordConfirmation }) {
  const response = await apiClient.put('/profile/password', {
    current_password: currentPassword,
    password,
    password_confirmation: passwordConfirmation,
  });

  return response.data;
}

export async function uploadProfilePhoto(file) {
  const formData = new FormData();
  formData.append('photo', file);

  const response = await apiClient.post('/profile/photo', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });

  return response.data;
}

export async function deleteProfilePhoto() {
  const response = await apiClient.delete('/profile/photo');

  return response.data;
}
