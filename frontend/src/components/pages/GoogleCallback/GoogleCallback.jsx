import { useEffect } from 'react';
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AuthTemplate from '../../templates/AuthTemplate';
import Spinner from '../../atoms/Spinner';
import styles from './GoogleCallback.module.css';

export default function GoogleCallback() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { loginWithToken } = useAuth();
  const token = searchParams.get('token');

  useEffect(() => {
    if (token) {
      loginWithToken(token);
      navigate('/', { replace: true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  if (!token) {
    return <Navigate to="/login?google_error=1" replace />;
  }

  return (
    <AuthTemplate>
      <div className={styles.box}>
        <Spinner />
        <p>Entrando com Google...</p>
      </div>
    </AuthTemplate>
  );
}
