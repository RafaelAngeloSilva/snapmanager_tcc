import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import FormField from '../../molecules/FormField';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import styles from './RegisterForm.module.css';

const EMPTY_FORM = { name: '', email: '', password: '', password_confirmation: '' };

export default function RegisterForm() {
  const { register } = useAuth();
  const navigate = useNavigate();

  const [form, setForm] = useState(EMPTY_FORM);
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  function handleChange(field) {
    return (event) => setForm((prev) => ({ ...prev, [field]: event.target.value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
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
        label="Senha"
        type="password"
        value={form.password}
        onChange={handleChange('password')}
        error={errors.password}
        autoComplete="new-password"
        placeholder="Mínimo 8 caracteres"
        required
      />

      <FormField
        id="password_confirmation"
        label="Confirmar senha"
        type="password"
        value={form.password_confirmation}
        onChange={handleChange('password_confirmation')}
        error={errors.password_confirmation}
        autoComplete="new-password"
        placeholder="••••••••"
        required
      />

      <Button type="submit" fullWidth isLoading={isSubmitting} className={styles.submit}>
        {isSubmitting ? <Spinner size="sm" /> : 'Criar conta'}
      </Button>

      <p className={styles.altAction}>
        Já tem conta? <Link to="/login">Entrar</Link>
      </p>
    </form>
  );
}
