import axios from 'axios';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';

// Instância própria, sem o interceptor de autenticação/redirect-pro-login do
// apiClient principal: quem acessa a galeria pública é o cliente, não um
// usuário do sistema, e nunca deve ser redirecionado para /login.
const publicClient = axios.create({
  baseURL: API_BASE_URL,
  headers: { Accept: 'application/json' },
});

export async function fetchPublicGallery(token) {
  const response = await publicClient.get(`/public/galleries/${token}`);

  return response.data.data;
}

export async function selectPublicGalleryImages(token, imageIds) {
  const response = await publicClient.post(`/public/galleries/${token}/select`, { image_ids: imageIds });

  return response.data;
}
