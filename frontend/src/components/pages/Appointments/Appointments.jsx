import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { fetchAppointments } from '../../../api/appointments';
import { addAppointmentToBoard } from '../../../api/kanban';
import Card from '../../atoms/Card';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import Button from '../../atoms/Button';
import InlineAlert from '../../molecules/InlineAlert';
import Pagination from '../../molecules/Pagination';
import AppointmentsToolbar from '../../organisms/AppointmentsToolbar';
import AppointmentsTable from '../../organisms/AppointmentsTable';
import AppointmentFormModal from '../../organisms/AppointmentFormModal';
import AppointmentDetailsModal from '../../organisms/AppointmentDetailsModal';
import DeleteAppointmentDialog from '../../organisms/DeleteAppointmentDialog';
import styles from './Appointments.module.css';

export default function Appointments() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();

  const [appointments, setAppointments] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  const [formModal, setFormModal] = useState({ isOpen: false, appointment: null });
  const [detailsModal, setDetailsModal] = useState({ isOpen: false, appointmentId: null });
  const [deleteDialog, setDeleteDialog] = useState({ isOpen: false, appointment: null });
  const [boardNotice, setBoardNotice] = useState(null);
  const [addingToBoardId, setAddingToBoardId] = useState(null);

  const load = useCallback((targetPage) => {
    setIsLoading(true);
    setError(null);

    fetchAppointments({ page: targetPage })
      .then((response) => {
        setAppointments(response.data);
        setMeta(response.meta);
      })
      .catch(() => setError('Não foi possível carregar os agendamentos.'))
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    load(1);
  }, [load]);

  // Permite chegar aqui direto de um card do quadro de Produção
  // (/agenda?appointment=12) com os detalhes já abertos. O parâmetro é
  // consumido uma vez e removido da URL.
  useEffect(() => {
    const target = searchParams.get('appointment');

    if (!target) return;

    setDetailsModal({ isOpen: true, appointmentId: Number(target) });
    setSearchParams({}, { replace: true });
  }, [searchParams, setSearchParams]);

  async function handleAddToBoard(appointment) {
    setAddingToBoardId(appointment.id);
    setBoardNotice(null);

    try {
      await addAppointmentToBoard(appointment.id);
      setBoardNotice({ tone: 'success', message: `"${appointment.title}" foi adicionado ao quadro de Produção.` });
    } catch (err) {
      if (err.response?.status === 409) {
        setBoardNotice({ tone: 'info', message: `"${appointment.title}" já está no quadro de Produção.` });
      } else if (err.response?.status === 403) {
        setBoardNotice({ tone: 'error', message: 'Você não tem permissão para adicionar agendamentos ao quadro.' });
      } else {
        setBoardNotice({ tone: 'error', message: 'Não foi possível adicionar ao quadro. Tente novamente.' });
      }
    } finally {
      setAddingToBoardId(null);
    }
  }

  function handleCreateClick() {
    setFormModal({ isOpen: true, appointment: null });
  }

  function handleEditFromRow(appointment) {
    setFormModal({ isOpen: true, appointment });
  }

  function handleEditFromDetails(appointment) {
    setDetailsModal({ isOpen: false, appointmentId: null });
    setFormModal({ isOpen: true, appointment });
  }

  function handleView(appointment) {
    setDetailsModal({ isOpen: true, appointmentId: appointment.id });
  }

  function handleDeleteClick(appointment) {
    setDeleteDialog({ isOpen: true, appointment });
  }

  function handleSaved() {
    setFormModal({ isOpen: false, appointment: null });
    load(meta.current_page);
  }

  function handleDeleted() {
    setDeleteDialog({ isOpen: false, appointment: null });

    // Se esse era o último agendamento da página (e não é a primeira),
    // a página ficaria vazia — volta para a anterior automaticamente.
    const willPageBeEmpty = appointments.length === 1 && meta.current_page > 1;
    const targetPage = willPageBeEmpty ? meta.current_page - 1 : meta.current_page;

    load(targetPage);
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Agenda</h1>
        <p className="page-subtitle">Sessões fotográficas agendadas.</p>
      </div>

      <AppointmentsToolbar onCreateClick={handleCreateClick} />

      {boardNotice && (
        <InlineAlert
          tone={boardNotice.tone}
          onDismiss={() => setBoardNotice(null)}
          action={
            boardNotice.tone !== 'error' ? (
              <Button variant="ghost" onClick={() => navigate('/producao')}>
                Ver no quadro
              </Button>
            ) : null
          }
        >
          {boardNotice.message}
        </InlineAlert>
      )}

      <Card padding="none">
        {isLoading && (
          <div className={styles.loadingBox}>
            <Spinner /> Carregando agendamentos...
          </div>
        )}

        {!isLoading && error && (
          <div className={styles.stateBox}>
            <EmptyState
              tone="error"
              layout="row"
              title="Erro ao carregar agendamentos"
              message={error}
              action={
                <Button variant="danger" onClick={() => load(meta.current_page)}>
                  Tentar novamente
                </Button>
              }
            />
          </div>
        )}

        {!isLoading && !error && appointments.length === 0 && (
          <div className={styles.stateBox}>
            <EmptyState
              title="Nenhum agendamento cadastrado"
              message="Comece criando o primeiro agendamento da agenda."
              action={<Button onClick={handleCreateClick}>Novo agendamento</Button>}
            />
          </div>
        )}

        {!isLoading && !error && appointments.length > 0 && (
          <AppointmentsTable
            appointments={appointments}
            onView={handleView}
            onEdit={handleEditFromRow}
            onDelete={handleDeleteClick}
            onAddToBoard={handleAddToBoard}
            addingToBoardId={addingToBoardId}
          />
        )}
      </Card>

      {!isLoading && !error && (
        <Pagination currentPage={meta.current_page} lastPage={meta.last_page} onPageChange={load} />
      )}

      <AppointmentFormModal
        isOpen={formModal.isOpen}
        appointment={formModal.appointment}
        onClose={() => setFormModal({ isOpen: false, appointment: null })}
        onSaved={handleSaved}
      />

      <AppointmentDetailsModal
        isOpen={detailsModal.isOpen}
        appointmentId={detailsModal.appointmentId}
        onClose={() => setDetailsModal({ isOpen: false, appointmentId: null })}
        onEdit={handleEditFromDetails}
      />

      <DeleteAppointmentDialog
        isOpen={deleteDialog.isOpen}
        appointment={deleteDialog.appointment}
        onClose={() => setDeleteDialog({ isOpen: false, appointment: null })}
        onDeleted={handleDeleted}
      />
    </div>
  );
}
