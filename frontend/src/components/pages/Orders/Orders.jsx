import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { fetchOrders } from '../../../api/orders';
import { addOrderToBoard } from '../../../api/kanban';
import Card from '../../atoms/Card';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import Button from '../../atoms/Button';
import InlineAlert from '../../molecules/InlineAlert';
import Pagination from '../../molecules/Pagination';
import OrdersToolbar from '../../organisms/OrdersToolbar';
import OrdersTable from '../../organisms/OrdersTable';
import OrderFormModal from '../../organisms/OrderFormModal';
import OrderDetailsModal from '../../organisms/OrderDetailsModal';
import UpdateOrderStatusModal from '../../organisms/UpdateOrderStatusModal';
import DeleteOrderDialog from '../../organisms/DeleteOrderDialog';
import styles from './Orders.module.css';
import { useAuth } from '../../../context/AuthContext';

export default function Orders() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();

  const [orders, setOrders] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  const [statusFilter, setStatusFilter] = useState('');
  const [clientFilter, setClientFilter] = useState(null);
  const [dueDateFilter, setDueDateFilter] = useState('');
  const hasActiveFilters = Boolean(statusFilter || clientFilter || dueDateFilter);

  const [formModal, setFormModal] = useState({ isOpen: false, order: null });
  const [detailsModal, setDetailsModal] = useState({ isOpen: false, orderId: null });
  const [statusModal, setStatusModal] = useState({ isOpen: false, order: null });
  const [deleteDialog, setDeleteDialog] = useState({ isOpen: false, order: null });
  const [boardNotice, setBoardNotice] = useState(null);
  const [addingToBoardId, setAddingToBoardId] = useState(null);

  const load = useCallback((targetPage, filters) => {
    setIsLoading(true);
    setError(null);

    fetchOrders({ page: targetPage, status: filters.status, clientId: filters.clientId, dueDate: filters.dueDate })
      .then((response) => {
        setOrders(response.data);
        setMeta(response.meta);
      })
      .catch(() => setError('Não foi possível carregar os pedidos.'))
      .finally(() => setIsLoading(false));
  }, []);

  // Roda na montagem (filtros vazios) e sempre que um filtro muda, voltando
  // para a página 1 automaticamente.
  useEffect(() => {
    load(1, { status: statusFilter, clientId: clientFilter?.id || '', dueDate: dueDateFilter });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter, clientFilter, dueDateFilter]);

  // Chegada a partir de um card do quadro de Produção (/pedidos?order=7):
  // abre os detalhes do pedido e limpa o parâmetro da URL.
  useEffect(() => {
    const target = searchParams.get('order');

    if (!target) return;

    setDetailsModal({ isOpen: true, orderId: Number(target) });
    setSearchParams({}, { replace: true });
  }, [searchParams, setSearchParams]);

  async function handleAddToBoard(order) {
    setAddingToBoardId(order.id);
    setBoardNotice(null);

    try {
      await addOrderToBoard(order.id);
      setBoardNotice({ tone: 'success', message: `"${order.title}" foi adicionado ao quadro de Produção.` });
    } catch (err) {
      if (err.response?.status === 409 && err.response.data?.code === 'no_columns') {
        setBoardNotice({
          tone: 'error',
          message: can('kanban.manageColumns')
            ? 'O quadro de Produção ainda não tem colunas. Crie uma coluna na tela de Produção e tente de novo.'
            : 'O quadro de Produção ainda não tem colunas. Peça a um administrador para criar as colunas.',
        });
      } else if (err.response?.status === 409) {
        setBoardNotice({ tone: 'info', message: `"${order.title}" já está no quadro de Produção.` });
      } else if (err.response?.status === 403) {
        setBoardNotice({ tone: 'error', message: 'Você não tem permissão para adicionar pedidos ao quadro.' });
      } else {
        setBoardNotice({ tone: 'error', message: 'Não foi possível adicionar ao quadro. Tente novamente.' });
      }
    } finally {
      setAddingToBoardId(null);
    }
  }

  function currentFilters() {
    return { status: statusFilter, clientId: clientFilter?.id || '', dueDate: dueDateFilter };
  }

  function handleClearFilters() {
    setStatusFilter('');
    setClientFilter(null);
    setDueDateFilter('');
  }

  function handlePageChange(newPage) {
    load(newPage, currentFilters());
  }

  function handleCreateClick() {
    setFormModal({ isOpen: true, order: null });
  }

  function handleEditFromRow(order) {
    setFormModal({ isOpen: true, order });
  }

  function handleEditFromDetails(order) {
    setDetailsModal({ isOpen: false, orderId: null });
    setFormModal({ isOpen: true, order });
  }

  function handleView(order) {
    setDetailsModal({ isOpen: true, orderId: order.id });
  }

  function handleChangeStatusClick(order) {
    setDetailsModal({ isOpen: false, orderId: null });
    setStatusModal({ isOpen: true, order });
  }

  function handleDeleteClick(order) {
    setDeleteDialog({ isOpen: true, order });
  }

  function handleSaved() {
    setFormModal({ isOpen: false, order: null });
    load(meta.current_page, currentFilters());
  }

  function handleStatusUpdated() {
    setStatusModal({ isOpen: false, order: null });
    load(meta.current_page, currentFilters());
  }

  function handleDeleted() {
    setDeleteDialog({ isOpen: false, order: null });

    // Se esse era o último pedido da página (e não é a primeira), a página
    // ficaria vazia — volta para a anterior automaticamente.
    const willPageBeEmpty = orders.length === 1 && meta.current_page > 1;
    const targetPage = willPageBeEmpty ? meta.current_page - 1 : meta.current_page;

    load(targetPage, currentFilters());
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Pedidos</h1>
        <p className="page-subtitle">Pedidos e status de produção.</p>
      </div>

      <OrdersToolbar
        statusFilter={statusFilter}
        onStatusFilterChange={setStatusFilter}
        clientFilter={clientFilter}
        onClientFilterChange={setClientFilter}
        dueDateFilter={dueDateFilter}
        onDueDateFilterChange={setDueDateFilter}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={handleClearFilters}
        onCreateClick={handleCreateClick}
      />

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
            <Spinner /> Carregando pedidos...
          </div>
        )}

        {!isLoading && error && (
          <div className={styles.stateBox}>
            <EmptyState
              tone="error"
              layout="row"
              title="Erro ao carregar pedidos"
              message={error}
              action={
                <Button variant="danger" onClick={() => load(meta.current_page, currentFilters())}>
                  Tentar novamente
                </Button>
              }
            />
          </div>
        )}

        {!isLoading && !error && orders.length === 0 && !hasActiveFilters && (
          <div className={styles.stateBox}>
            <EmptyState
              title="Nenhum pedido cadastrado"
              message="Comece criando o primeiro pedido do estúdio."
              action={can('orders.manage') ? <Button onClick={handleCreateClick}>Novo pedido</Button> : null}
            />
          </div>
        )}

        {!isLoading && !error && orders.length === 0 && hasActiveFilters && (
          <div className={styles.stateBox}>
            <EmptyState title="Nenhum resultado" message="Nenhum pedido corresponde aos filtros aplicados." />
          </div>
        )}

        {!isLoading && !error && orders.length > 0 && (
          <OrdersTable
            orders={orders}
            onView={handleView}
            onEdit={handleEditFromRow}
            onChangeStatus={(order) => setStatusModal({ isOpen: true, order })}
            onDelete={handleDeleteClick}
            onAddToBoard={handleAddToBoard}
            addingToBoardId={addingToBoardId}
          />
        )}
      </Card>

      {!isLoading && !error && (
        <Pagination currentPage={meta.current_page} lastPage={meta.last_page} onPageChange={handlePageChange} />
      )}

      <OrderFormModal
        isOpen={formModal.isOpen}
        order={formModal.order}
        onClose={() => setFormModal({ isOpen: false, order: null })}
        onSaved={handleSaved}
      />

      <OrderDetailsModal
        isOpen={detailsModal.isOpen}
        orderId={detailsModal.orderId}
        onClose={() => setDetailsModal({ isOpen: false, orderId: null })}
        onEdit={handleEditFromDetails}
        onChangeStatus={handleChangeStatusClick}
      />

      <UpdateOrderStatusModal
        isOpen={statusModal.isOpen}
        order={statusModal.order}
        onClose={() => setStatusModal({ isOpen: false, order: null })}
        onUpdated={handleStatusUpdated}
      />

      <DeleteOrderDialog
        isOpen={deleteDialog.isOpen}
        order={deleteDialog.order}
        onClose={() => setDeleteDialog({ isOpen: false, order: null })}
        onDeleted={handleDeleted}
      />
    </div>
  );
}
