import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import Spinner from '../components/atoms/Spinner';
import Button from '../components/atoms/Button';

const centered = {
  display: 'flex',
  flexDirection: 'column',
  gap: '0.75rem',
  alignItems: 'center',
  justifyContent: 'center',
  minHeight: '40vh',
  textAlign: 'center',
};

/**
 * Evita que alguém sem permissão chegue a uma tela inteira quebrada de
 * erros 403 ao digitar a URL direto (o Sidebar já esconde o link, mas isso
 * não impede a navegação manual). Redireciona para "/pedidos", a única
 * tela que os 4 perfis conseguem acessar — a autorização real continua
 * sendo o Laravel, isto é somente uma conveniência de navegação.
 *
 * Depois de um F5, de um link direto ou do login com Google, o perfil do
 * usuário ainda está carregando: espera por ele antes de decidir — senão
 * qualquer recarga mandaria a pessoa para "/pedidos".
 */
export default function RequirePermission({ permission }) {
  const { can, isUserLoading, userLoadFailed, retryLoadUser } = useAuth();

  if (isUserLoading) {
    return (
      <div style={centered} role="status">
        <Spinner />
      </div>
    );
  }

  if (userLoadFailed) {
    return (
      <div style={centered} role="alert">
        <p>Não foi possível carregar o seu perfil. Verifique a conexão e tente de novo.</p>
        <Button onClick={retryLoadUser}>Tentar novamente</Button>
      </div>
    );
  }

  if (!can(permission)) {
    return <Navigate to="/pedidos" replace />;
  }

  return <Outlet />;
}
