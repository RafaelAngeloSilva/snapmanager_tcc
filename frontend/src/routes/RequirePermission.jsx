import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

/**
 * Evita que alguém sem permissão chegue a uma tela inteira quebrada de
 * erros 403 ao digitar a URL direto (o Sidebar já esconde o link, mas isso
 * não impede a navegação manual). Redireciona para "/pedidos", a única
 * tela que os 4 perfis conseguem acessar — a autorização real continua
 * sendo o Laravel, isto é somente uma conveniência de navegação.
 */
export default function RequirePermission({ permission }) {
  const { can } = useAuth();

  if (!can(permission)) {
    return <Navigate to="/pedidos" replace />;
  }

  return <Outlet />;
}
