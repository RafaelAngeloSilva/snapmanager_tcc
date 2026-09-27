import { useState } from 'react';
import { Link } from 'react-router-dom';
import FormField from '../../molecules/FormField';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { CheckCircleIcon } from '../../atoms/Icon';
import { forgotPassword } from '../../../api/auth';
import styles from './ForgotPasswordForm.module.css';

export default function ForgotPasswordForm() {
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [sent, setSent] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();
    setError('');
    setIsSubmitting(true);

    try {
      await forgotPassword(email);
      setSent(true);
    } catch (err) {
      if (err.response?.status === 422) {
        setError('Informe um e-mail válido.');
      } else {
        setError('Não foi possível processar o pedido. Tente novamente.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  if (sent) {
    return (
      <div className={styles.sentBox}>
        <span className={styles.sentIcon}>
          <CheckCircleIcon width={22} height={22} />
        </span>
        <h1 className={styles.title}>Verifique seu e-mail</h1>
        <p className={styles.subtitle}>
          Se <strong>{email}</strong> estiver cadastrado, enviamos um link para redefinir a senha. Em ambiente
          local, o link aparece no arquivo de log do backend (veja o DEMO.md).
        </p>
        <Link to="/login" className={styles.backLink}>
          Voltar para o login
        </Link>
      </div>
    );
  }

  return (
    <form className={styles.form} onSubmit={handleSubmit}>
      <div className={styles.header}>
        <span className={styles.eyebrow}>Painel administrativo</span>
        <h1 className={styles.title}>Esqueci minha senha</h1>
        <p className={styles.subtitle}>Informe seu e-mail e enviaremos um link para redefinir sua senha.</p>
      </div>

      {error && <div className={styles.error}>{error}</div>}

      <FormField
        id="email"
        label="E-mail"
        type="email"
        value={email}
        onChange={(event) => setEmail(event.target.value)}
        autoComplete="username"
        placeholder="voce@snapmanager.test"
        required
      />

      <Button type="submit" fullWidth isLoading={isSubmitting} className={styles.submit}>
        {isSubmitting ? <Spinner size="sm" /> : 'Enviar link de redefinição'}
      </Button>

      <p className={styles.altAction}>
        <Link to="/login">Voltar para o login</Link>
      </p>
    </form>
  );
}
