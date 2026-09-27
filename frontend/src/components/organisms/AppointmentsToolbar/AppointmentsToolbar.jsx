import Button from '../../atoms/Button';
import { PlusIcon } from '../../atoms/Icon';
import styles from './AppointmentsToolbar.module.css';

export default function AppointmentsToolbar({ onCreateClick }) {
  return (
    <div className={styles.toolbar}>
      <Button onClick={onCreateClick}>
        <PlusIcon />
        Novo agendamento
      </Button>
    </div>
  );
}
