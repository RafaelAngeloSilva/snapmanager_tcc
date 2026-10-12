import { useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import { exchangeGoogleCode } from '../../../api/auth';
import { consumeGoogleNonce } from '../../../utils/googleOAuth';
import AuthTemplate from '../../templates/AuthTemplate';
import Spinner from '../../atoms/Spinner';
import styles from './GoogleCallback.module.css';

/**
 * Volta do login com Google: /login/google/callback#code=...
 *
 * O código vem no FRAGMENTO (#) — não é enviado à Vercel nem aparece em
 * logs — e só vale junto com o nonce guardado por esta aba ao iniciar o
 * login (ver utils/googleOAuth.js). A troca por token acontece uma única
 * vez (o ref evita a segunda execução do efeito no StrictMode do React).
 */
export default function GoogleCallback() {
  const navigate = useNavigate();
  const { loginWithToken } = useAuth();
  const started = useRef(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;

    const code = new URLSearchParams(window.location.hash.slice(1)).get('code');
    const nonce = consumeGoogleNonce();

    // Tira o código da barra de endereços/histórico imediatamente.
    window.history.replaceState(null, '', window.location.pathname);

    if (!code || !nonce) {
      navigate(`/login?google_error=${code ? 'expired' : 'failed'}`, { replace: true });
      return;
    }

    exchangeGoogleCode(code, nonce)
      .then((data) => {
        loginWithToken(data.token);
        navigate('/', { replace: true });
      })
      .catch((err) => {
        const reason = err.response?.status === 429 ? 'failed' : 'exchange_failed';
        navigate(`/login?google_error=${reason}`, { replace: true });
      });
  }, [loginWithToken, navigate]);

  return (
    <AuthTemplate>
      <div className={styles.box} role="status" aria-live="polite">
        <Spinner />
        <p>Autenticando com o Google…</p>
      </div>
    </AuthTemplate>
  );
}
