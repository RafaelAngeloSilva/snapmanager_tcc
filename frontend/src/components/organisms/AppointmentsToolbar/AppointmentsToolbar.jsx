import Button from '../../atoms/Button';
import { PlusIcon } from '../../atoms/Icon';
import styles from './AppointmentsToolbar.module.css';
import { useAuth } from '../../../context/AuthContext';

export default function AppointmentsToolbar({ onCreateClick }) {
  const { can } = useAuth();

  if (!can('appointments.manage')) return null;

  return (
    <div className={styles.toolbar}>
      <Button onClick={onCreateClick}>
        <PlusIcon />
        Novo agendamento
      </Button>
    </div>
  );
}
