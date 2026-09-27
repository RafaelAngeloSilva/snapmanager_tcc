import axios from 'axios';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';

export const TOKEN_STORAGE_KEY = 'snapmanager_token';

// Raiz do backend sem o sufixo "/api" — usada pelas rotas de login com
// Google, que vivem em routes/web.php (navegação de página inteira até o
// Google e de volta), não em routes/api.php.
export const API_ROOT_URL = API_BASE_URL.replace(/\/api\/?$/, '');

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    Accept: 'application/json',
  },
});

apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_STORAGE_KEY);

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  return config;
});

// Sessão expirada ou token inválido: limpa a sessão local e manda para o
// login. Tratamento simples, sem estado global de "expirado" no React.
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem(TOKEN_STORAGE_KEY);

      if (window.location.pathname !== '/login') {
        window.location.href = '/login';
      }
    }

    return Promise.reject(error);
  },
);

export default apiClient;
