import { useEffect, useRef, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Avatar from '../../atoms/Avatar';
import FormField from '../../molecules/FormField';
import { useAuth } from '../../../context/AuthContext';
import { roleLabel } from '../../../utils/permissions';
import { deleteProfilePhoto, updateProfile, updateProfilePassword, uploadProfilePhoto } from '../../../api/profile';
import styles from './ProfileModal.module.css';

function extractErrorMessage(err, fallback) {
  const errors = err.response?.data?.errors;

  if (errors) {
    return Object.values(errors).flat()[0] || fallback;
  }

  return err.response?.data?.message || fallback;
}

export default function ProfileModal({ isOpen, onClose }) {
  const { user, updateUser } = useAuth();
  const fileInputRef = useRef(null);

  const [name, setName] = useState('');
  const [nameStatus, setNameStatus] = useState('idle'); // idle | loading | success | error
  const [nameError, setNameError] = useState('');

  const [photoStatus, setPhotoStatus] = useState('idle');
  const [photoError, setPhotoError] = useState('');

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState('');
  const [passwordStatus, setPasswordStatus] = useState('idle');
  const [passwordMessage, setPasswordMessage] = useState('');

  // Reseta o formulário a cada abertura, com os dados atuais do contexto —
  // evita mostrar rascunhos de uma sessão anterior de edição.
  useEffect(() => {
    if (isOpen) {
      setName(user?.name || '');
      setNameStatus('idle');
      setNameError('');
      setPhotoStatus('idle');
      setPhotoError('');
      setCurrentPassword('');
      setNewPassword('');
      setNewPasswordConfirmation('');
      setPasswordStatus('idle');
      setPasswordMessage('');
    }
  }, [isOpen, user]);

  async function handleNameSubmit(event) {
    event.preventDefault();
    setNameStatus('loading');
    setNameError('');

    try {
      const updated = await updateProfile({ name });
      updateUser({ name: updated.name });
      setNameStatus('success');
    } catch (err) {
      setNameError(extractErrorMessage(err, 'Não foi possível salvar as alterações.'));
      setNameStatus('error');
    }
  }

  async function handlePhotoSelected(event) {
    const file = event.target.files?.[0];
    event.target.value = '';

    if (!file) return;

    setPhotoStatus('loading');
    setPhotoError('');

    try {
      const updated = await uploadProfilePhoto(file);
      updateUser({ profile_photo_url: updated.profile_photo_url });
      setPhotoStatus('idle');
    } catch (err) {
      setPhotoError(extractErrorMessage(err, 'Não foi possível enviar a foto.'));
      setPhotoStatus('error');
    }
  }

  async function handlePhotoRemove() {
    setPhotoStatus('loading');
    setPhotoError('');

    try {
      await deleteProfilePhoto();
      updateUser({ profile_photo_url: null });
      setPhotoStatus('idle');
    } catch {
      setPhotoError('Não foi possível remover a foto.');
      setPhotoStatus('error');
    }
  }

  async function handlePasswordSubmit(event) {
    event.preventDefault();
    setPasswordStatus('loading');
    setPasswordMessage('');

    if (newPassword !== newPasswordConfirmation) {
      setPasswordStatus('error');
      setPasswordMessage('As senhas não coincidem.');
      return;
    }

    try {
      await updateProfilePassword({ currentPassword, password: newPassword, passwordConfirmation: newPasswordConfirmation });
      setPasswordStatus('success');
      setPasswordMessage('Senha alterada com sucesso.');
      setCurrentPassword('');
      setNewPassword('');
      setNewPasswordConfirmation('');
    } catch (err) {
      setPasswordStatus('error');
      setPasswordMessage(
        err.response?.status === 422
          ? extractErrorMessage(err, 'Não foi possível alterar a senha.')
          : 'Não foi possível alterar a senha.'
      );
    }
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Meu perfil" size="lg">
      <div className={styles.sections}>
        <section className={styles.section}>
          <h3 className={styles.sectionTitle}>Foto de perfil</h3>

          <div className={styles.photoRow}>
            <Avatar name={user?.name} photoUrl={user?.profile_photo_url} size="lg" />

            <div className={styles.photoActions}>
              <input
                ref={fileInputRef}
                type="file"
                accept="image/jpeg,image/jpg,image/png,image/webp"
                className={styles.hiddenInput}
                onChange={handlePhotoSelected}
              />
              <Button
                type="button"
                variant="secondary"
                onClick={() => fileInputRef.current?.click()}
                disabled={photoStatus === 'loading'}
              >
                {photoStatus === 'loading' ? <Spinner size="sm" /> : 'Alterar foto'}
              </Button>

              {user?.profile_photo_url && (
                <Button type="button" variant="ghost" onClick={handlePhotoRemove} disabled={photoStatus === 'loading'}>
                  Remover foto
                </Button>
              )}

              <p className={styles.hint}>JPG, PNG ou WEBP, até 2MB.</p>
              {photoError && <p className={styles.errorText}>{photoError}</p>}
            </div>
          </div>
        </section>

        <form className={styles.section} onSubmit={handleNameSubmit}>
          <h3 className={styles.sectionTitle}>Dados do perfil</h3>

          <FormField label="Nome" id="profile-name" value={name} onChange={(event) => setName(event.target.value)} required />
          <FormField label="E-mail" id="profile-email" value={user?.email || ''} disabled />
          <FormField label="Perfil" id="profile-role" value={roleLabel(user?.role)} disabled />

          {nameError && <p className={styles.errorText}>{nameError}</p>}
          {nameStatus === 'success' && <p className={styles.successText}>Alterações salvas com sucesso.</p>}

          <Button type="submit" disabled={nameStatus === 'loading'} className={styles.sectionSubmit}>
            {nameStatus === 'loading' ? <Spinner size="sm" /> : 'Salvar alterações'}
          </Button>
        </form>

        <form className={styles.section} onSubmit={handlePasswordSubmit}>
          <h3 className={styles.sectionTitle}>Alterar senha</h3>

          <FormField
            label="Senha atual"
            id="profile-current-password"
            type="password"
            autoComplete="current-password"
            value={currentPassword}
            onChange={(event) => setCurrentPassword(event.target.value)}
            required
          />
          <FormField
            label="Nova senha"
            id="profile-new-password"
            type="password"
            autoComplete="new-password"
            value={newPassword}
            onChange={(event) => setNewPassword(event.target.value)}
            minLength={8}
            required
          />
          <FormField
            label="Confirmar nova senha"
            id="profile-new-password-confirmation"
            type="password"
            autoComplete="new-password"
            value={newPasswordConfirmation}
            onChange={(event) => setNewPasswordConfirmation(event.target.value)}
            minLength={8}
            required
          />

          {passwordStatus === 'error' && <p className={styles.errorText}>{passwordMessage}</p>}
          {passwordStatus === 'success' && <p className={styles.successText}>{passwordMessage}</p>}

          <Button type="submit" disabled={passwordStatus === 'loading'} className={styles.sectionSubmit}>
            {passwordStatus === 'loading' ? <Spinner size="sm" /> : 'Alterar senha'}
          </Button>
        </form>
      </div>
    </Modal>
  );
}
