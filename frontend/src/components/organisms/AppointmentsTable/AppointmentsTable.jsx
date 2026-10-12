import Table, { TableHead, TableBody, TableRow, TableHeaderCell, TableCell } from '../../atoms/Table';
import Badge from '../../atoms/Badge';
import Button from '../../atoms/Button';
import { EyeIcon, EditIcon, TrashIcon, BoardIcon } from '../../atoms/Icon';
import { formatDateTimeRange } from '../../../utils/formatters';
import { APPOINTMENT_STATUS_LABELS, APPOINTMENT_STATUS_BADGE_VARIANT } from '../../../utils/appointmentStatus';
import { useAuth } from '../../../context/AuthContext';
import styles from './AppointmentsTable.module.css';

export default function AppointmentsTable({ appointments, onView, onEdit, onDelete, onAddToBoard, addingToBoardId }) {
  const { can } = useAuth();

  return (
    <Table>
      <TableHead>
        <TableRow>
          <TableHeaderCell>Agendamento</TableHeaderCell>
          <TableHeaderCell>Cliente</TableHeaderCell>
          <TableHeaderCell>Profissional</TableHeaderCell>
          <TableHeaderCell>Data e horário</TableHeaderCell>
          <TableHeaderCell>Status</TableHeaderCell>
          <TableHeaderCell align="right">Ações</TableHeaderCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {appointments.map((appointment) => (
          <TableRow key={appointment.id}>
            <TableCell>
              <div className={styles.titleCell}>
                <span className={styles.title}>{appointment.title}</span>
                {appointment.session_type && <span className={styles.sessionType}>{appointment.session_type}</span>}
              </div>
            </TableCell>

            <TableCell>
              <span className={appointment.client ? styles.person : styles.muted}>
                {appointment.client?.name || 'Cliente removido'}
              </span>
            </TableCell>

            <TableCell>
              <span className={appointment.user ? styles.person : styles.muted}>
                {appointment.user?.name || '—'}
              </span>
            </TableCell>

            <TableCell>{formatDateTimeRange(appointment.starts_at, appointment.ends_at)}</TableCell>

            <TableCell>
              <Badge variant={APPOINTMENT_STATUS_BADGE_VARIANT[appointment.status] || 'neutral'}>
                {APPOINTMENT_STATUS_LABELS[appointment.status] || appointment.status}
              </Badge>
            </TableCell>

            <TableCell align="right">
              <div className={styles.actions}>
                <Button variant="ghost" size="icon" onClick={() => onView(appointment)} aria-label={`Ver detalhes de ${appointment.title}`}>
                  <EyeIcon />
                </Button>
                {can('kanban.import') && (
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => onAddToBoard(appointment)}
                    isLoading={addingToBoardId === appointment.id}
                    aria-label={`Adicionar ${appointment.title} à Produção`}
                    title="Adicionar à Produção"
                  >
                    <BoardIcon />
                  </Button>
                )}
                {can('appointments.manage') && (
                  <>
                    <Button variant="ghost" size="icon" onClick={() => onEdit(appointment)} aria-label={`Editar ${appointment.title}`}>
                      <EditIcon />
                    </Button>
                    <Button variant="ghost" size="icon" onClick={() => onDelete(appointment)} aria-label={`Excluir ${appointment.title}`}>
                      <TrashIcon />
                    </Button>
                  </>
                )}
              </div>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
