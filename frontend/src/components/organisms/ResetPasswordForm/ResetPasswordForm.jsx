import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import FormField from '../../molecules/FormField';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import { resetPassword } from '../../../api/auth';
import styles from './ResetPasswordForm.module.css';

export default function ResetPasswordForm() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();

  const token = searchParams.get('token') || '';
  const email = searchParams.get('email') || '';

  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [success, setSuccess] = useState(false);

  if (!token || !email) {
    return (
      <EmptyState
        tone="error"
        title="Link inválido"
        message="Este link de redefinição de senha está incompleto. Solicite um novo link na tela de login."
        action={
          <Link to="/forgot-password">
            <Button>Solicitar novo link</Button>
          </Link>
        }
      />
    );
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setErrors({});
    setGeneralError('');
    setIsSubmitting(true);

    try {
      await resetPassword({ token, email, password, password_confirmation: passwordConfirmation });
      setSuccess(true);
      setTimeout(() => navigate('/login', { replace: true }), 2500);
    } catch (err) {
      if (err.response?.status === 422) {
        const fieldErrors = {};

        Object.entries(err.response.data.errors || {}).forEach(([field, messages]) => {
          fieldErrors[field] = messages[0];
        });

        setErrors(fieldErrors);
        // O backend reporta token inválido/expirado no campo "email" (é o
        // erro do password broker, não um erro de validação do formulário).
        if (fieldErrors.email) {
          setGeneralError(fieldErrors.email);
        }
      } else {
        setGeneralError('Não foi possível redefinir a senha. Tente novamente.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  if (success) {
    return (
      <EmptyState
        title="Senha redefinida!"
        message="Sua senha foi alterada com sucesso. Redirecionando para o login..."
      />
    );
  }

  return (
    <form className={styles.form} onSubmit={handleSubmit}>
      <div className={styles.header}>
        <span className={styles.eyebrow}>Painel administrativo</span>
        <h1 className={styles.title}>Redefinir senha</h1>
        <p className={styles.subtitle}>Escolha uma nova senha para {email}.</p>
      </div>

      {generalError && <div className={styles.error}>{generalError}</div>}

      <FormField
        id="password"
        label="Nova senha"
        type="password"
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        error={errors.password}
        autoComplete="new-password"
        placeholder="Mínimo 8 caracteres"
        required
      />

      <FormField
        id="password_confirmation"
        label="Confirmar nova senha"
        type="password"
        value={passwordConfirmation}
        onChange={(event) => setPasswordConfirmation(event.target.value)}
        error={errors.password_confirmation}
        autoComplete="new-password"
        placeholder="••••••••"
        required
      />

      <Button type="submit" fullWidth isLoading={isSubmitting} className={styles.submit}>
        {isSubmitting ? <Spinner size="sm" /> : 'Redefinir senha'}
      </Button>
    </form>
  );
}
