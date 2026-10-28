import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import Spinner from '../components/atoms/Spinner';

const centered = {
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  minHeight: '100vh',
};

/**
 * Área autenticada. Ter um token salvo NÃO basta: enquanto o servidor não
 * confirmar quem é o usuário (/me), nada do painel é desenhado — antes, um
 * token inválido mostrava o dashboard por um instante até o 401 derrubar a
 * sessão. Se o servidor responder 401, o interceptor do axios limpa o
 * token e manda para o login.
 */
export default function ProtectedRoute() {
  const { isAuthenticated, isUserLoading } = useAuth();
  const location = useLocation();

  if (!isAuthenticated) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  if (isUserLoading) {
    return (
      <div style={centered} role="status" aria-label="Verificando sua sessão">
        <Spinner />
      </div>
    );
  }

  return <Outlet />;
}
