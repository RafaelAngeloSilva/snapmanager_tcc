import { useNavigate } from 'react-router-dom';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Badge from '../../atoms/Badge';
import Avatar from '../../atoms/Avatar';
import PriorityBadge from '../../atoms/PriorityBadge';
import TaskOriginBadge from '../../atoms/TaskOriginBadge';
import { formatDateTimeRange, formatDueDate, formatDateTime } from '../../../utils/formatters';
import { ORDER_STATUS_LABELS, ORDER_STATUS_BADGE_VARIANT } from '../../../utils/orderStatus';
import { roleLabel } from '../../../utils/permissions';
import styles from './KanbanTaskModal.module.css';

/**
 * Detalhes de um card. Não faz requisição: o quadro já tem o card completo
 * (o KanbanTaskResource traz o resumo dos relacionamentos), então abrir o
 * modal é instantâneo.
 *
 * O bloco "Origem" é o que diferencia este quadro de um Trello genérico —
 * o card sabe de qual agendamento ou pedido veio e leva até o registro.
 */
export default function KanbanTaskModal({ isOpen, task, column, canManage, onClose, onEdit, onDelete }) {
  const navigate = useNavigate();

  if (!task) return null;

  function goTo(path) {
    onClose();
    navigate(path);
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Detalhes do card"
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Fechar
          </Button>
          {canManage && (
            <>
              <Button variant="danger" onClick={() => onDelete(task)}>
                Excluir
              </Button>
              <Button onClick={() => onEdit(task)}>Editar</Button>
            </>
          )}
        </>
      }
    >
      <div className={styles.header}>
        <h2 className={styles.title}>{task.title}</h2>
        <div className={styles.headerBadges}>
          <TaskOriginBadge origin={task.origin} />
          <PriorityBadge priority={task.priority} />
        </div>
      </div>

      {task.description && <p className={styles.description}>{task.description}</p>}

      <dl className={styles.details}>
        <div>
          <dt>Coluna</dt>
          <dd>{column?.name || '—'}</dd>
        </div>
        <div>
          <dt>Responsável</dt>
          <dd>
            {task.assignee ? (
              <span className={styles.assignee}>
                <Avatar name={task.assignee.name} photoUrl={task.assignee.profile_photo_url} size="sm" />
                {task.assignee.name} — {roleLabel(task.assignee.role)}
              </span>
            ) : (
              'Sem responsável'
            )}
          </dd>
        </div>
        <div>
          <dt>Prazo</dt>
          <dd>{task.due_date ? formatDueDate(task.due_date) : '—'}</dd>
        </div>
        <div>
          <dt>Conclusão</dt>
          <dd>{task.completed_at ? formatDateTime(task.completed_at) : 'Em aberto'}</dd>
        </div>
        <div>
          <dt>Cliente</dt>
          <dd>{task.client?.name || '—'}</dd>
        </div>
        <div>
          <dt>Criado em</dt>
          <dd>{formatDateTime(task.created_at)}</dd>
        </div>
      </dl>

      {(task.appointment || task.order || task.gallery) && (
        <>
          <p className={styles.sectionTitle}>Registro de origem</p>

          <ul className={styles.links}>
            {task.appointment && (
              <li className={styles.link}>
                <div className={styles.linkInfo}>
                  <span className={styles.linkLabel}>Agendamento</span>
                  <strong>{task.appointment.title}</strong>
                  <span className={styles.linkMeta}>
                    {formatDateTimeRange(task.appointment.starts_at, task.appointment.ends_at)}
                  </span>
                </div>
                <Button variant="ghost" onClick={() => goTo(`/agenda?appointment=${task.appointment.id}`)}>
                  Ver na Agenda
                </Button>
              </li>
            )}

            {task.order && (
              <li className={styles.link}>
                <div className={styles.linkInfo}>
                  <span className={styles.linkLabel}>Pedido</span>
                  <strong>{task.order.title}</strong>
                  <span className={styles.linkMeta}>
                    <Badge variant={ORDER_STATUS_BADGE_VARIANT[task.order.status] || 'neutral'}>
                      {ORDER_STATUS_LABELS[task.order.status] || task.order.status}
                    </Badge>
                    {task.order.due_date && ` · entrega em ${formatDueDate(task.order.due_date)}`}
                  </span>
                </div>
                <Button variant="ghost" onClick={() => goTo(`/pedidos?order=${task.order.id}`)}>
                  Ver em Pedidos
                </Button>
              </li>
            )}

            {task.gallery && (
              <li className={styles.link}>
                <div className={styles.linkInfo}>
                  <span className={styles.linkLabel}>Galeria</span>
                  <strong>{task.gallery.title}</strong>
                </div>
                <Button variant="ghost" onClick={() => goTo('/galerias')}>
                  Ver em Galerias
                </Button>
              </li>
            )}
          </ul>

          {/* Deixa explícito para quem usa o sistema que as duas coisas são
              separadas nesta versão — o status do pedido continua sendo
              alterado na tela de Pedidos. */}
          {task.order && (
            <p className={styles.note}>
              Mover o card entre colunas não altera o status do pedido. As duas informações são mantidas separadas.
            </p>
          )}
        </>
      )}
    </Modal>
  );
}
