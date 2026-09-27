import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import { fetchGoogleAuthStatus } from '../../../api/auth';
import { API_ROOT_URL } from '../../../api/client';
import FormField from '../../molecules/FormField';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { ApertureIcon, GoogleIcon } from '../../atoms/Icon';
import styles from './LoginForm.module.css';

export default function LoginForm() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isGoogleAvailable, setIsGoogleAvailable] = useState(false);

  const redirectTo = location.state?.from?.pathname || '/';

  useEffect(() => {
    if (searchParams.get('google_error')) {
      setError('Não foi possível entrar com o Google. Tente novamente.');
    }
  }, [searchParams]);

  useEffect(() => {
    fetchGoogleAuthStatus()
      .then((data) => setIsGoogleAvailable(Boolean(data.available)))
      .catch(() => setIsGoogleAvailable(false));
  }, []);

  async function handleSubmit(event) {
    event.preventDefault();
    setError('');
    setIsSubmitting(true);

    try {
      await login(email, password);
      navigate(redirectTo, { replace: true });
    } catch (err) {
      if (err.response?.status === 422) {
        setError('E-mail ou senha inválidos.');
      } else {
        setError('Não foi possível entrar. Verifique se o backend está rodando.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <form className={styles.form} onSubmit={handleSubmit}>
      <div className={styles.header}>
        <div className={styles.brandMark}>
          <span className={styles.brandIcon} aria-hidden="true">
            <ApertureIcon width={15} height={15} />
          </span>
          <span className={styles.brandWordmark}>
            SnapManager<span className={styles.brandDot} aria-hidden="true" />
          </span>
        </div>
        <span className={styles.eyebrow}>Painel administrativo</span>
        <h1 className={styles.title}>Bem-vindo de volta</h1>
        <p className={styles.subtitle}>Entre com suas credenciais para continuar.</p>
      </div>

      {error && <div className={styles.error}>{error}</div>}

      <div className={styles.fields}>
        <FormField
          id="email"
          label="E-mail"
          type="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          autoComplete="username"
          placeholder="voce@snapmanager.test"
          className={styles.loginInput}
          required
        />

        <FormField
          id="password"
          label="Senha"
          type="password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          autoComplete="current-password"
          placeholder="••••••••"
          className={styles.loginInput}
          required
        />
      </div>

      <div className={styles.links}>
        <Link to="/forgot-password" className={styles.link}>
          Esqueci minha senha
        </Link>
      </div>

      <Button type="submit" fullWidth isLoading={isSubmitting} className={styles.submit}>
        {isSubmitting ? <Spinner size="sm" /> : 'Entrar'}
      </Button>

      {isGoogleAvailable && (
        <>
          <div className={styles.divider}>
            <span>ou</span>
          </div>

          <Button
            type="button"
            variant="secondary"
            fullWidth
            onClick={() => window.location.assign(`${API_ROOT_URL}/auth/google/redirect`)}
          >
            <GoogleIcon />
            Entrar com Google
          </Button>
        </>
      )}

      <p className={styles.altAction}>
        Não tem conta? <Link to="/register">Criar conta</Link>
      </p>
    </form>
  );
}
