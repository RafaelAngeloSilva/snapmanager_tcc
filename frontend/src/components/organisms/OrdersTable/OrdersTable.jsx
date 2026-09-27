import Table, { TableHead, TableBody, TableRow, TableHeaderCell, TableCell } from '../../atoms/Table';
import Badge from '../../atoms/Badge';
import Button from '../../atoms/Button';
import { useAuth } from '../../../context/AuthContext';
import { EyeIcon, EditIcon, TrashIcon, StatusChangeIcon, WarningIcon, BoardIcon } from '../../atoms/Icon';
import { formatCurrency, formatDueDate } from '../../../utils/formatters';
import { ORDER_STATUS_LABELS, ORDER_STATUS_BADGE_VARIANT, isOrderOverdue } from '../../../utils/orderStatus';
import styles from './OrdersTable.module.css';

export default function OrdersTable({
  orders,
  onView,
  onEdit,
  onChangeStatus,
  onDelete,
  onAddToBoard,
  addingToBoardId,
}) {
  const { can } = useAuth();
  const canManage = can('orders.manage');
  const canDelete = can('orders.delete');
  const canAddToBoard = can('kanban.import');

  return (
    <Table>
      <TableHead>
        <TableRow>
          <TableHeaderCell>Pedido</TableHeaderCell>
          <TableHeaderCell>Cliente</TableHeaderCell>
          <TableHeaderCell>Prazo</TableHeaderCell>
          <TableHeaderCell>Total</TableHeaderCell>
          <TableHeaderCell>Status</TableHeaderCell>
          <TableHeaderCell align="right">Ações</TableHeaderCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {orders.map((order) => {
          const overdue = isOrderOverdue(order);

          return (
            <TableRow key={order.id}>
              <TableCell>
                <div className={styles.titleCell}>
                  <span className={styles.title}>{order.title}</span>
                  {order.service_type && <span className={styles.serviceType}>{order.service_type}</span>}
                </div>
              </TableCell>

              <TableCell>
                <span className={order.client ? styles.person : styles.muted}>
                  {order.client?.name || 'Cliente removido'}
                </span>
              </TableCell>

              <TableCell>
                {order.due_date ? (
                  <span className={`${styles.dueDate} ${overdue ? styles.overdue : ''}`}>
                    {overdue && <WarningIcon />}
                    {formatDueDate(order.due_date)}
                  </span>
                ) : (
                  <span className={styles.muted}>—</span>
                )}
              </TableCell>

              <TableCell>
                <span className={styles.total}>{formatCurrency(order.total)}</span>
              </TableCell>

              <TableCell>
                <Badge variant={ORDER_STATUS_BADGE_VARIANT[order.status] || 'neutral'}>
                  {ORDER_STATUS_LABELS[order.status] || order.status}
                </Badge>
              </TableCell>

              <TableCell align="right">
                <div className={styles.actions}>
                  <Button variant="ghost" size="icon" onClick={() => onView(order)} aria-label={`Ver detalhes de ${order.title}`}>
                    <EyeIcon />
                  </Button>
                  <Button
                    variant="ghost"
                    onClick={() => onChangeStatus(order)}
                    aria-label={`Alterar status de ${order.title}`}
                  >
                    <StatusChangeIcon />
                  </Button>
                  {canAddToBoard && (
                    <Button
                      variant="ghost"
                      size="icon"
                      onClick={() => onAddToBoard(order)}
                      isLoading={addingToBoardId === order.id}
                      aria-label={`Adicionar ${order.title} à Produção`}
                      title="Adicionar à Produção"
                    >
                      <BoardIcon />
                    </Button>
                  )}
                  {canManage && (
                    <Button variant="ghost" size="icon" onClick={() => onEdit(order)} aria-label={`Editar ${order.title}`}>
                      <EditIcon />
                    </Button>
                  )}
                  {canDelete && (
                    <Button variant="ghost" size="icon" onClick={() => onDelete(order)} aria-label={`Excluir ${order.title}`}>
                      <TrashIcon />
                    </Button>
                  )}
                </div>
              </TableCell>
            </TableRow>
          );
        })}
      </TableBody>
    </Table>
  );
}
