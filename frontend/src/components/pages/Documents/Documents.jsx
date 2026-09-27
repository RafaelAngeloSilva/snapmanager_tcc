import { useCallback, useEffect, useRef, useState } from 'react';
import Card from '../../atoms/Card';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import Button from '../../atoms/Button';
import { PlusIcon } from '../../atoms/Icon';
import Pagination from '../../molecules/Pagination';
import InlineAlert from '../../molecules/InlineAlert';
import DocumentFilters from '../../molecules/DocumentFilters';
import DocumentsTable from '../../organisms/DocumentsTable';
import DocumentFormModal from '../../organisms/DocumentFormModal';
import DocumentDetailsModal from '../../organisms/DocumentDetailsModal';
import { fetchDocuments } from '../../../api/documents';
import { useAuth } from '../../../context/AuthContext';
import useDocumentQuickActions from '../../../hooks/useDocumentQuickActions';
import { savedNotice } from '../../../utils/documents';
import styles from './Documents.module.css';

const SEARCH_DEBOUNCE_MS = 300;

export default function Documents() {
  const { can } = useAuth();
  const canManage = can('documents.manage');

  const [documents, setDocuments] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(null);

  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');

  const [formModal, setFormModal] = useState({ isOpen: false, document: null });
  const [detailsId, setDetailsId] = useState(null);

  // Ignora respostas antigas se o filtro mudar antes da anterior voltar.
  const requestId = useRef(0);

  const load = useCallback((page) => {
    const current = ++requestId.current;
    setIsLoading(true);
    setError('');

    fetchDocuments({ page, status, search: debouncedSearch })
      .then((response) => {
        if (current !== requestId.current) return;
        setDocuments(response.data);
        setMeta(response.meta);
      })
      .catch(() => current === requestId.current && setError('Não foi possível carregar os documentos.'))
      .finally(() => current === requestId.current && setIsLoading(false));
  }, [status, debouncedSearch]);

  useEffect(() => {
    load(1);
  }, [load]);

  useEffect(() => {
    const handle = setTimeout(() => setDebouncedSearch(search.trim()), SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(handle);
  }, [search]);

  /** Atualiza a linha na tabela sem recarregar tudo (ex.: após sincronizar). */
  function handleChanged(updated) {
    setDocuments((prev) => prev.map((item) => (item.id === updated.id ? { ...item, ...updated } : item)));
  }

  // Sincronizar/baixar direto da tabela — mesmo handler da seção de
  // documentos vinculados de Pedidos e Agenda.
  const quickActions = useDocumentQuickActions({ onUpdated: handleChanged, onNotice: setNotice });

  function handleSaved(saved, wasEditing) {
    setFormModal({ isOpen: false, document: null });
    setNotice(savedNotice(saved, wasEditing));
    load(wasEditing ? meta.current_page : 1);
    setDetailsId(saved.id);
  }

  function handleDeleted() {
    setDetailsId(null);
    setNotice({ tone: 'success', message: 'Rascunho excluído.' });

    const willPageBeEmpty = documents.length === 1 && meta.current_page > 1;
    load(willPageBeEmpty ? meta.current_page - 1 : meta.current_page);
  }

  const hasFilters = Boolean(status || debouncedSearch);

  return (
    <div>
      <div className={styles.headerRow}>
        <div className="page-header">
          <h1 className="page-title">Documentos</h1>
          <p className="page-subtitle">Contratos e autorizações com assinatura eletrônica pela Clicksign.</p>
        </div>

        {canManage && (
          <Button onClick={() => setFormModal({ isOpen: true, document: null })}>
            <PlusIcon />
            Novo documento
          </Button>
        )}
      </div>

      {notice && (
        <InlineAlert tone={notice.tone} onDismiss={() => setNotice(null)}>
          {notice.message}
        </InlineAlert>
      )}

      <DocumentFilters status={status} search={search} onStatusChange={setStatus} onSearchChange={setSearch} />

      <Card padding="none">
        {isLoading && (
          <div className={styles.loadingBox}>
            <Spinner /> Carregando documentos...
          </div>
        )}

        {!isLoading && error && (
          <div className={styles.stateBox}>
            <EmptyState
              tone="error"
              layout="row"
              title="Erro ao carregar documentos"
              message={error}
              action={
                <Button variant="danger" onClick={() => load(meta.current_page)}>
                  Tentar novamente
                </Button>
              }
            />
          </div>
        )}

        {!isLoading && !error && documents.length === 0 && (
          <div className={styles.stateBox}>
            {hasFilters ? (
              <EmptyState title="Nenhum resultado" message="Nenhum documento corresponde aos filtros aplicados." />
            ) : (
              <EmptyState
                title="Nenhum documento ainda"
                message="Cadastre um contrato em PDF, adicione os signatários e envie para assinatura."
                action={canManage ? <Button onClick={() => setFormModal({ isOpen: true, document: null })}>Novo documento</Button> : null}
              />
            )}
          </div>
        )}

        {!isLoading && !error && documents.length > 0 && (
          <DocumentsTable
            documents={documents}
            onView={(document) => setDetailsId(document.id)}
            onSync={quickActions.sync}
            onDownload={quickActions.download}
            isBusy={quickActions.isBusy}
            busy={quickActions.busy}
          />
        )}
      </Card>

      {!isLoading && !error && <Pagination currentPage={meta.current_page} lastPage={meta.last_page} onPageChange={load} />}

      <DocumentFormModal
        isOpen={formModal.isOpen}
        document={formModal.document}
        onClose={() => setFormModal({ isOpen: false, document: null })}
        onSaved={handleSaved}
      />

      <DocumentDetailsModal
        isOpen={detailsId !== null && !formModal.isOpen}
        documentId={detailsId}
        onClose={() => setDetailsId(null)}
        onEdit={(document) => setFormModal({ isOpen: true, document })}
        onChanged={handleChanged}
        onDeleted={handleDeleted}
      />
    </div>
  );
}
