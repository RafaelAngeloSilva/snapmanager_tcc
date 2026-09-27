import apiClient from './client';

export async function fetchGalleries({ page = 1, status = '', clientId = '' } = {}) {
  const params = { page };

  if (status) params.status = status;
  if (clientId) params.client_id = clientId;

  const response = await apiClient.get('/galleries', { params });

  return response.data;
}

export async function fetchGallery(id) {
  const response = await apiClient.get(`/galleries/${id}`);

  return response.data.data;
}

export async function createGallery(payload) {
  const response = await apiClient.post('/galleries', payload);

  return response.data.data;
}

export async function updateGallery(id, payload) {
  const response = await apiClient.put(`/galleries/${id}`, payload);

  return response.data.data;
}

export async function deleteGallery(id) {
  await apiClient.delete(`/galleries/${id}`);
}

export async function closeGallery(id) {
  const response = await apiClient.patch(`/galleries/${id}/close`);

  return response.data;
}

export async function uploadGalleryImages(galleryId, files, onUploadProgress) {
  const formData = new FormData();

  files.forEach((file) => formData.append('images[]', file));

  const response = await apiClient.post(`/galleries/${galleryId}/images`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
    onUploadProgress,
  });

  return response.data.data;
}

export async function deleteGalleryImage(galleryId, imageId) {
  await apiClient.delete(`/galleries/${galleryId}/images/${imageId}`);
}

export async function uploadGalleryWatermarkImage(galleryId, file) {
  const formData = new FormData();
  formData.append('watermark_image', file);

  const response = await apiClient.post(`/galleries/${galleryId}/watermark-image`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });

  return response.data.data;
}

// Os arquivos ficam em disco privado e só são servidos pela rota controlada
// abaixo (autenticada). Por isso o <img> não pode apontar direto pra "url" —
// buscamos como blob (o interceptor do apiClient já anexa o Bearer token) e
// convertemos em object URL local.
export async function fetchGalleryImageBlob(relativeUrl) {
  const response = await apiClient.get(relativeUrl, { responseType: 'blob' });

  return URL.createObjectURL(response.data);
}
