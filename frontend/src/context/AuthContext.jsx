import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import * as authApi from '../api/auth';
import { TOKEN_STORAGE_KEY } from '../api/client';
import { roleCan } from '../utils/permissions';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [token, setToken] = useState(() => localStorage.getItem(TOKEN_STORAGE_KEY));
  const [user, setUser] = useState(null);

  // Após um F5 a página recarrega com token salvo mas sem os dados do
  // usuário em memória: busca o perfil para preencher nome/role na sidebar.
  useEffect(() => {
    if (!token || user) {
      return;
    }

    authApi
      .fetchMe()
      .then(setUser)
      .catch(() => {
        // 401 já é tratado globalmente pelo interceptor do axios (redireciona
        // para /login); outros erros aqui não travam a UI.
      });
  }, [token, user]);

  async function login(email, password) {
    const data = await authApi.login(email, password);

    localStorage.setItem(TOKEN_STORAGE_KEY, data.token);
    setToken(data.token);
    setUser(data.user);

    return data.user;
  }

  async function register(payload) {
    const data = await authApi.register(payload);

    localStorage.setItem(TOKEN_STORAGE_KEY, data.token);
    setToken(data.token);
    setUser(data.user);

    return data.user;
  }

  // Usado pelo callback do login com Google: o token já vem pronto (emitido
  // pelo backend após o handshake OAuth), só precisa ser guardado — o efeito
  // acima cuida de buscar o perfil do usuário a partir dele.
  function loginWithToken(newToken) {
    localStorage.setItem(TOKEN_STORAGE_KEY, newToken);
    setToken(newToken);
  }

  async function logout() {
    try {
      await authApi.logout();
    } catch {
      // Mesmo se a chamada falhar (ex.: token já expirado), seguimos
      // limpando a sessão localmente.
    } finally {
      localStorage.removeItem(TOKEN_STORAGE_KEY);
      setToken(null);
      setUser(null);
    }
  }

  // Permite que a tela de perfil reflita name/foto no Header/Sidebar
  // imediatamente após salvar, sem precisar de um novo fetchMe()/login.
  function updateUser(patch) {
    setUser((prev) => (prev ? { ...prev, ...patch } : prev));
  }

  const value = useMemo(
    () => ({
      token,
      user,
      isAuthenticated: Boolean(token),
      login,
      register,
      loginWithToken,
      logout,
      updateUser,
      // Só controla o que a UI mostra/esconde — a autorização de fato é
      // sempre validada pelo Laravel (middleware "role" nas rotas), nunca
      // aqui. Ver frontend/src/utils/permissions.js.
      can: (permission) => Boolean(user && roleCan(user.role, permission)),
      hasRole: (...roles) => Boolean(user && roles.includes(user.role)),
    }),
    [token, user],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth deve ser usado dentro de um AuthProvider.');
  }

  return context;
}
