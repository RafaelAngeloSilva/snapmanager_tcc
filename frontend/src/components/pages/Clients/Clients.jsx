import { useCallback, useEffect, useRef, useState } from 'react';
import { fetchClients } from '../../../api/clients';
import Card from '../../atoms/Card';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import Button from '../../atoms/Button';
import Pagination from '../../molecules/Pagination';
import ClientsToolbar from '../../organisms/ClientsToolbar';
import ClientsTable from '../../organisms/ClientsTable';
import ClientFormModal from '../../organisms/ClientFormModal';
import ClientDetailsModal from '../../organisms/ClientDetailsModal';
import DeleteClientDialog from '../../organisms/DeleteClientDialog';
import styles from './Clients.module.css';

const SEARCH_DEBOUNCE_MS = 350;

export default function Clients() {
  const [clients, setClients] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  const [searchInput, setSearchInput] = useState('');
  const [searchTerm, setSearchTerm] = useState('');

  const [formModal, setFormModal] = useState({ isOpen: false, client: null });
  const [detailsModal, setDetailsModal] = useState({ isOpen: false, clientId: null });
  const [deleteDialog, setDeleteDialog] = useState({ isOpen: false, client: null });

  // Guarda a requisição em andamento pra poder cancelá-la se uma busca mais
  // nova começar antes dela terminar — sem isso, digitar rápido ("R", "Ra",
  // "Raf"...) poderia deixar uma resposta antiga chegar depois da mais
  // recente e sobrescrever a tela com um resultado desatualizado.
  const pendingRequest = useRef(null);

  const load = useCallback((targetPage, term) => {
    pendingRequest.current?.abort();
    const controller = new AbortController();
    pendingRequest.current = controller;

    setIsLoading(true);
    setError(null);

    fetchClients({ page: targetPage, search: term, signal: controller.signal })
      .then((response) => {
        setClients(response.data);
        setMeta(response.meta);
      })
      .catch((err) => {
        if (err.code === 'ERR_CANCELED') return;
        setError('Não foi possível carregar os clientes.');
      })
      .finally(() => {
        if (pendingRequest.current === controller) {
          setIsLoading(false);
        }
      });
  }, []);

  // Carga inicial.
  useEffect(() => {
    load(1, '');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Busca com debounce: espera o usuário parar de digitar, consulta a API
  // de verdade (não só filtra a página atual) e volta para a página 1.
  const isFirstRun = useRef(true);

  useEffect(() => {
    if (isFirstRun.current) {
      isFirstRun.current = false;
      return undefined;
    }

    const handle = setTimeout(() => {
      setSearchTerm(searchInput);
      load(1, searchInput);
    }, SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  function handlePageChange(newPage) {
    load(newPage, searchTerm);
  }

  function handleCreateClick() {
    setFormModal({ isOpen: true, client: null });
  }

  function handleEditFromRow(client) {
    setFormModal({ isOpen: true, client });
  }

  function handleEditFromDetails(client) {
    setDetailsModal({ isOpen: false, clientId: null });
    setFormModal({ isOpen: true, client });
  }

  function handleView(client) {
    setDetailsModal({ isOpen: true, clientId: client.id });
  }

  function handleDeleteClick(client) {
    setDeleteDialog({ isOpen: true, client });
  }

  function handleSaved() {
    setFormModal({ isOpen: false, client: null });
    load(meta.current_page, searchTerm);
  }

  function handleDeleted() {
    setDeleteDialog({ isOpen: false, client: null });

    // Se esse era o último cliente da página (e não é a primeira página),
    // a página ficaria vazia — volta para a anterior automaticamente.
    const willPageBeEmpty = clients.length === 1 && meta.current_page > 1;
    const targetPage = willPageBeEmpty ? meta.current_page - 1 : meta.current_page;

    load(targetPage, searchTerm);
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Clientes</h1>
        <p className="page-subtitle">Cadastro e gestão de clientes do estúdio.</p>
      </div>

      <ClientsToolbar searchTerm={searchInput} onSearchChange={setSearchInput} onCreateClick={handleCreateClick} />

      <Card padding="none">
        {isLoading && (
          <div className={styles.loadingBox}>
            <Spinner /> Carregando clientes...
          </div>
        )}

        {!isLoading && error && (
          <div className={styles.stateBox}>
            <EmptyState
              tone="error"
              layout="row"
              title="Erro ao carregar clientes"
              message={error}
              action={
                <Button variant="danger" onClick={() => load(meta.current_page, searchTerm)}>
                  Tentar novamente
                </Button>
              }
            />
          </div>
        )}

        {!isLoading && !error && clients.length === 0 && !searchTerm && (
          <div className={styles.stateBox}>
            <EmptyState
              title="Nenhum cliente cadastrado"
              message="Comece criando o primeiro cliente do estúdio."
              action={<Button onClick={handleCreateClick}>Novo cliente</Button>}
            />
          </div>
        )}

        {!isLoading && !error && clients.length === 0 && searchTerm && (
          <div className={styles.stateBox}>
            <EmptyState
              title="Nenhum resultado"
              message={`Nenhum cliente encontrado para "${searchTerm}".`}
            />
          </div>
        )}

        {!isLoading && !error && clients.length > 0 && (
          <ClientsTable
            clients={clients}
            onView={handleView}
            onEdit={handleEditFromRow}
            onDelete={handleDeleteClick}
          />
        )}
      </Card>

      {!isLoading && !error && (
        <Pagination currentPage={meta.current_page} lastPage={meta.last_page} onPageChange={handlePageChange} />
      )}

      <ClientFormModal
        isOpen={formModal.isOpen}
        client={formModal.client}
        onClose={() => setFormModal({ isOpen: false, client: null })}
        onSaved={handleSaved}
      />

      <ClientDetailsModal
        isOpen={detailsModal.isOpen}
        clientId={detailsModal.clientId}
        onClose={() => setDetailsModal({ isOpen: false, clientId: null })}
        onEdit={handleEditFromDetails}
      />

      <DeleteClientDialog
        isOpen={deleteDialog.isOpen}
        client={deleteDialog.client}
        onClose={() => setDeleteDialog({ isOpen: false, client: null })}
        onDeleted={handleDeleted}
      />
    </div>
  );
}
