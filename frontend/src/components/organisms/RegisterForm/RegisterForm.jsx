import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import FormField from '../../molecules/FormField';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import styles from './RegisterForm.module.css';
import { FIELD_LIMITS } from '../../../utils/fieldLimits';
import PasswordInput from '../../atoms/PasswordInput';
import { GoogleIcon } from '../../atoms/Icon';
import { fetchGoogleAuthStatus } from '../../../api/auth';
import { startGoogleLogin } from '../../../utils/googleOAuth';

const EMPTY_FORM = { name: '', email: '', password: '', password_confirmation: '' };

export default function RegisterForm() {
  const { register } = useAuth();
  const navigate = useNavigate();

  const [form, setForm] = useState(EMPTY_FORM);
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isGoogleAvailable, setIsGoogleAvailable] = useState(false);
  const [isRedirectingToGoogle, setIsRedirectingToGoogle] = useState(false);
  const isBusy = isSubmitting || isRedirectingToGoogle;

  // "Continuar com Google" só aparece quando o back-end está configurado.
  // Com e-mail novo, o Google cria a conta (mesmas regras do cadastro).
  useEffect(() => {
    fetchGoogleAuthStatus()
      .then((data) => setIsGoogleAvailable(Boolean(data.available)))
      .catch(() => setIsGoogleAvailable(false));
  }, []);

  function handleGoogleSignup() {
    if (isBusy) return;
    setIsRedirectingToGoogle(true);
    startGoogleLogin();
  }

  function handleChange(field) {
    return (event) => setForm((prev) => ({ ...prev, [field]: event.target.value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    if (isBusy) return;
    setErrors({});
    setGeneralError('');
    setIsSubmitting(true);

    try {
      await register(form);
      navigate('/', { replace: true });
    } catch (err) {
      if (err.response?.status === 422) {
        const fieldErrors = {};

        Object.entries(err.response.data.errors || {}).forEach(([field, messages]) => {
          fieldErrors[field] = messages[0];
        });

        setErrors(fieldErrors);
      } else if (err.response?.status === 403 || err.response?.status === 429) {
        // Cadastro desativado no servidor ou excesso de tentativas: mostra a
        // mensagem do back-end.
        setGeneralError(err.response.data?.message || 'Não foi possível criar a conta.');
      } else {
        setGeneralError('Não foi possível criar a conta. Verifique se o backend está rodando.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <form className={styles.form} onSubmit={handleSubmit}>
      <div className={styles.header}>
        <span className={styles.eyebrow}>Painel administrativo</span>
        <h1 className={styles.title}>Criar conta</h1>
        <p className={styles.subtitle}>Cadastre-se para acessar o SnapManager.</p>
      </div>

      {generalError && <div className={styles.error}>{generalError}</div>}

      <FormField
        id="name"
        maxLength={FIELD_LIMITS.PERSON_NAME}
        label="Nome"
        value={form.name}
        onChange={handleChange('name')}
        error={errors.name}
        autoComplete="name"
        placeholder="Seu nome completo"
        required
      />

      <FormField
        id="email"
        maxLength={FIELD_LIMITS.EMAIL}
        label="E-mail"
        type="email"
        value={form.email}
        onChange={handleChange('email')}
        error={errors.email}
        autoComplete="username"
        placeholder="voce@exemplo.com"
        required
      />

      <FormField
        id="password"
        maxLength={FIELD_LIMITS.PASSWORD_MAX}
        label="Senha"
        as={PasswordInput}
        value={form.password}
        onChange={handleChange('password')}
        error={errors.password}
        autoComplete="new-password"
        placeholder="Mínimo 8 caracteres"
        required
      />

      <FormField
        id="password_confirmation"
        maxLength={FIELD_LIMITS.PASSWORD_MAX}
        label="Confirmar senha"
        as={PasswordInput}
        value={form.password_confirmation}
        onChange={handleChange('password_confirmation')}
        error={errors.password_confirmation}
        autoComplete="new-password"
        placeholder="••••••••"
        required
      />

      <Button type="submit" fullWidth isLoading={isSubmitting} disabled={isBusy} className={styles.submit}>
        {isSubmitting ? <Spinner size="sm" /> : 'Criar conta'}
      </Button>

      {isGoogleAvailable && (
        <>
          <div className={styles.divider}>
            <span>ou</span>
          </div>

          <Button type="button" variant="secondary" fullWidth onClick={handleGoogleSignup} disabled={isBusy}>
            {isRedirectingToGoogle ? (
              <>
                <Spinner size="sm" />
                Autenticando…
              </>
            ) : (
              <>
                <GoogleIcon />
                Continuar com Google
              </>
            )}
          </Button>
        </>
      )}

      <p className={styles.altAction}>
        Já tem conta? <Link to="/login">Entrar</Link>
      </p>
    </form>
  );
}
