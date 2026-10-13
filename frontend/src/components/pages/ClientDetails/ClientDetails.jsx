import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import Button from '../../atoms/Button';
import Card from '../../atoms/Card';
import Modal from '../../atoms/Modal';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import LoyaltyBadge from '../../atoms/LoyaltyBadge';
import { ChevronLeftIcon, EditIcon } from '../../atoms/Icon';
import PreferenceNotes from '../../organisms/PreferenceNotes';
import ClientTimeline from '../../organisms/ClientTimeline';
import HistoryEntryFormModal from '../../organisms/HistoryEntryFormModal';
import ClientFormModal from '../../organisms/ClientFormModal';
import { fetchClient } from '../../../api/clients';
import { deleteHistoryEntry, fetchClientHistory } from '../../../api/clientHistory';
import { useAuth } from '../../../context/AuthContext';
import { formatDate } from '../../../utils/formatters';
import { formatDocument } from '../../../utils/cpf';
import { formatFullAddress } from '../../../utils/address';
import styles from './ClientDetails.module.css';

const TABS = [
  { value: 'history', label: 'Histórico' },
  { value: 'data', label: 'Dados' },
];

function plural(count, one, many) {
  return `${count} ${count === 1 ? one : many}`;
}

/**
 * Página do cliente: resumo + selo de fidelidade no topo e as abas
 * Histórico (Notas de Preferência + linha do tempo) e Dados (cadastro).
 */
export default function ClientDetails() {
  const { id } = useParams();
  const { can } = useAuth();
  const canUseHistory = can('clients.history');
  const canManageClient = can('clients.manage');

  const [client, setClient] = useState(null);
  const [history, setHistory] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState('');
  const [tab, setTab] = useState('history');

  const [entryModal, setEntryModal] = useState({ isOpen: false, entry: null });
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [isDeleting, setIsDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');
  const [isEditingClient, setIsEditingClient] = useState(false);

  const loadHistory = useCallback(() => fetchClientHistory(id).then(setHistory), [id]);

  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    setError('');

    Promise.all([fetchClient(id), fetchClientHistory(id)])
      .then(([clientData, historyData]) => {
        if (cancelled) return;
        setClient(clientData);
        setHistory(historyData);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(
          err.response?.status === 404
            ? 'Este cliente não existe ou foi excluído.'
            : err.response?.status === 403
              ? 'Você não tem permissão para ver este cliente.'
              : 'Não foi possível carregar o cliente.',
        );
      })
      .finally(() => !cancelled && setIsLoading(false));

    return () => {
      cancelled = true;
    };
  }, [id]);

  async function handleEntrySaved() {
    setEntryModal({ isOpen: false, entry: null });
    await loadHistory().catch(() => {});
  }

  async function handleConfirmDelete() {
    if (!deleteTarget || isDeleting) return;
    setIsDeleting(true);
    setDeleteError('');

    try {
      await deleteHistoryEntry(id, deleteTarget.entry.id);
      setDeleteTarget(null);
      await loadHistory().catch(() => {});
    } catch (err) {
      setDeleteError(
        err.response?.status === 403
          ? 'Somente quem criou a anotação (ou um administrador) pode excluí-la.'
          : 'Não foi possível excluir. Tente novamente.',
      );
    } finally {
      setIsDeleting(false);
    }
  }

  if (isLoading) {
    return (
      <div className={styles.loading} role="status">
        <Spinner /> Carregando cliente...
      </div>
    );
  }

  if (error || !client || !history) {
    return (
      <Card>
        <EmptyState
          tone="error"
          title="Cliente indisponível"
          message={error || 'Não foi possível carregar o cliente.'}
          action={
            <Link to="/clientes" className={styles.backLink}>
              <ChevronLeftIcon /> Voltar para clientes
            </Link>
          }
        />
      </Card>
    );
  }

  const { summary } = history;

  return (
    <div className={styles.page}>
      <Link to="/clientes" className={styles.backLink}>
        <ChevronLeftIcon /> Clientes
      </Link>

      <Card className={styles.hero}>
        <div className={styles.identity}>
          <div className={styles.nameRow}>
            <h1 className={styles.name}>{client.name}</h1>
            <LoyaltyBadge
              tier={summary.loyalty.tier}
              completed={summary.completed_shoots}
              loyalFrom={summary.loyalty.loyal_from}
              vipFrom={summary.loyalty.vip_from}
            />
          </div>
          {canManageClient && (
            <Button variant="secondary" onClick={() => setIsEditingClient(true)}>
              <EditIcon />
              Editar cadastro
            </Button>
          )}
        </div>

        <dl className={styles.stats}>
          <div>
            <dt>Cliente desde</dt>
            <dd>{formatDate(summary.client_since)}</dd>
          </div>
          <div>
            <dt>Ensaios realizados</dt>
            <dd>{plural(summary.completed_shoots, 'ensaio', 'ensaios')}</dd>
          </div>
          <div>
            <dt>Último ensaio</dt>
            <dd>{summary.last_shoot_at ? formatDate(summary.last_shoot_at) : '—'}</dd>
          </div>
          <div>
            <dt>Próximo ensaio</dt>
            <dd>{summary.next_shoot_at ? formatDate(summary.next_shoot_at) : 'Nenhum agendado'}</dd>
          </div>
        </dl>
      </Card>

      <div className={styles.tabs} role="tablist" aria-label="Seções do cliente">
        {TABS.map((item) => (
          <button
            key={item.value}
            type="button"
            role="tab"
            id={`tab-${item.value}`}
            aria-selected={tab === item.value}
            aria-controls={`panel-${item.value}`}
            className={`${styles.tab} ${tab === item.value ? styles.tabActive : ''}`}
            onClick={() => setTab(item.value)}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === 'history' && (
        <div role="tabpanel" id="panel-history" aria-labelledby="tab-history" className={styles.historyGrid}>
          <div className={styles.notesColumn}>
            <PreferenceNotes
              clientId={client.id}
              notes={history.preference_notes}
              canEdit={canUseHistory}
              maxLength={history.limits?.preference_notes}
              onSaved={(notes) => setHistory((prev) => ({ ...prev, preference_notes: notes }))}
            />
          </div>

          <Card className={styles.timelineCard}>
            <ClientTimeline
              events={history.timeline}
              canAdd={canUseHistory}
              onAdd={() => setEntryModal({ isOpen: true, entry: null })}
              onEdit={(event) => setEntryModal({ isOpen: true, entry: event })}
              onDelete={(event) => {
                setDeleteError('');
                setDeleteTarget(event);
              }}
            />
          </Card>
        </div>
      )}

      {tab === 'data' && (
        <Card role="tabpanel" id="panel-data" aria-labelledby="tab-data">
          <dl className={styles.details}>
            <div>
              <dt>E-mail</dt>
              <dd>{client.email || '—'}</dd>
            </div>
            <div>
              <dt>Telefone</dt>
              <dd>{client.phone || '—'}</dd>
            </div>
            <div>
              <dt>Documento</dt>
              <dd>{client.document ? formatDocument(client.document) : '—'}</dd>
            </div>
            <div>
              <dt>Cadastrado em</dt>
              <dd>{formatDate(client.created_at)}</dd>
            </div>
            <div className={styles.fullWidth}>
              <dt>Endereço</dt>
              <dd>{formatFullAddress(client) || '—'}</dd>
            </div>
            <div className={styles.fullWidth}>
              <dt>Observações</dt>
              <dd>{client.notes || '—'}</dd>
            </div>
          </dl>
        </Card>
      )}

      <HistoryEntryFormModal
        isOpen={entryModal.isOpen}
        clientId={client.id}
        entry={entryModal.entry}
        categories={history.categories}
        shoots={history.shoots}
        onClose={() => setEntryModal({ isOpen: false, entry: null })}
        onSaved={handleEntrySaved}
      />

      <Modal
        isOpen={Boolean(deleteTarget)}
        onClose={() => !isDeleting && setDeleteTarget(null)}
        title="Excluir anotação"
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeleteTarget(null)} disabled={isDeleting}>
              Cancelar
            </Button>
            <Button variant="danger" onClick={handleConfirmDelete} disabled={isDeleting}>
              {isDeleting ? <Spinner size="sm" /> : 'Excluir'}
            </Button>
          </>
        }
      >
        <p className={styles.confirmText}>
          Excluir a anotação <strong>{deleteTarget?.title}</strong>? Esta ação não pode ser desfeita.
        </p>
        {deleteError && (
          <p className={styles.confirmError} role="alert">
            {deleteError}
          </p>
        )}
      </Modal>

      {canManageClient && (
        <ClientFormModal
          isOpen={isEditingClient}
          client={client}
          onClose={() => setIsEditingClient(false)}
          onSaved={(saved) => {
            setIsEditingClient(false);
            if (saved) setClient(saved);
            loadHistory().catch(() => {});
          }}
        />
      )}
    </div>
  );
}
