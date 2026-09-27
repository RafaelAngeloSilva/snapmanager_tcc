import { useCallback, useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import DocumentStatusBadge from '../../atoms/DocumentStatusBadge';
import { DownloadIcon, PlusIcon, RefreshIcon } from '../../atoms/Icon';
import InlineAlert from '../../molecules/InlineAlert';
import DocumentDetailsModal from '../DocumentDetailsModal';
import DocumentFormModal from '../DocumentFormModal';
import { fetchDocuments } from '../../../api/documents';
import { useAuth } from '../../../context/AuthContext';
import useDocumentQuickActions from '../../../hooks/useDocumentQuickActions';
import { formatDate } from '../../../utils/formatters';
import { DOCUMENT_ACTION_LABELS as LABELS, documentActions, savedNotice, signaturesText } from '../../../utils/documents';
import styles from './LinkedDocumentsSection.module.css';

const EMPTY_MESSAGE = {
  order: 'Nenhum documento vinculado a este pedido.',
  appointment: 'Nenhum documento vinculado a este agendamento.',
};

/**
 * "Documentos vinculados" dentro dos detalhes de um pedido ou agendamento.
 *
 * Não duplica o módulo Documentos: a lista vem do mesmo GET /documents
 * (com filtro order_id/appointment_id, e a visibilidade por perfil aplicada
 * pelo back-end), "Ver detalhes" abre o DocumentDetailsModal existente e
 * "Novo documento" abre o DocumentFormModal existente com o contexto
 * pré-selecionado. Quais ações aparecem vem de `abilities` de cada
 * documento — permissão e estado, calculados no back-end.
 *
 * Os modais filhos vão por portal para o <body>: o painel do modal-pai usa
 * transform/overflow, que prenderiam um modal aninhado dentro dele.
 * `onOverlayChange` avisa o pai para não fechar junto (Esc/clique fora).
 *
 * @param {'order'|'appointment'} context
 */
export default function LinkedDocumentsSection({
  context,
  contextId,
  client,
  presetOrder = null,
  presetAppointment = null,
  onOverlayChange,
}) {
  const { can } = useAuth();

  const [documents, setDocuments] = useState([]);
  const [total, setTotal] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(null);

  const [detailsId, setDetailsId] = useState(null);
  // Formulário existente: novo (document null, com contexto) ou edição de rascunho.
  const [form, setForm] = useState({ isOpen: false, document: null });

  const load = useCallback(() => {
    setIsLoading(true);
    setError('');

    const filter = context === 'order' ? { orderId: contextId } : { appointmentId: contextId };

    fetchDocuments(filter)
      .then((response) => {
        setDocuments(response.data);
        setTotal(response.meta?.total ?? response.data.length);
      })
      .catch(() => setError('Não foi possível carregar os documentos vinculados.'))
      .finally(() => setIsLoading(false));
  }, [context, contextId]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    onOverlayChange?.(detailsId !== null || form.isOpen);
  }, [detailsId, form.isOpen, onOverlayChange]);

  // Estável entre renders: o formulário se reinicia quando initialValues muda.
  const initialValues = useMemo(
    () => ({
      client: client ? { id: client.id, name: client.name } : null,
      order: presetOrder ? { id: presetOrder.id, title: presetOrder.title } : null,
      appointment: presetAppointment
        ? { id: presetAppointment.id, title: presetAppointment.title, starts_at: presetAppointment.starts_at }
        : null,
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [client?.id, client?.name, presetOrder?.id, presetOrder?.title, presetAppointment?.id, presetAppointment?.title, presetAppointment?.starts_at],
  );

  /** Troca um item da lista pelo payload mais novo, sem recarregar tudo. */
  const replaceDocument = useCallback((updated) => {
    setDocuments((prev) => prev.map((item) => (item.id === updated.id ? { ...item, ...updated } : item)));
  }, []);

  // Sincronizar/baixar: mesmo handler da tabela da aba Documentos.
  const quickActions = useDocumentQuickActions({ onUpdated: replaceDocument, onNotice: setNotice });
  const { busy, isBusy } = quickActions;

  function handleSaved(saved, wasEditing) {
    setForm({ isOpen: false, document: null });
    setNotice(savedNotice(saved, wasEditing));
    // Recarrega a lista: o documento pode ter entrado (novo) ou saído
    // (edição trocou o pedido/agendamento) deste contexto.
    load();
    setDetailsId(saved.id);
  }

  function handleDeleted() {
    setDocuments((prev) => prev.filter((item) => item.id !== detailsId));
    setTotal((prev) => Math.max(0, prev - 1));
    setDetailsId(null);
    setNotice({ tone: 'success', message: 'Rascunho excluído.' });
  }

  // Cliente removido (soft delete) não pode receber documento novo.
  const canCreate = can('documents.manage') && Boolean(client);

  return (
    <section className={styles.section} aria-labelledby={`linked-documents-${context}-${contextId}`}>
      <div className={styles.header}>
        <h3 id={`linked-documents-${context}-${contextId}`} className={styles.title}>
          Documentos vinculados
          {!isLoading && !error && documents.length > 0 && <span className={styles.count}>{total}</span>}
        </h3>

        {canCreate && (
          <Button variant="secondary" onClick={() => setForm({ isOpen: true, document: null })}>
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

      {isLoading && (
        <div className={styles.state}>
          <Spinner size="sm" /> Carregando documentos...
        </div>
      )}

      {!isLoading && error && (
        <div className={styles.stateError} role="alert">
          <span>{error}</span>
          <Button variant="ghost" onClick={load}>
            Tentar novamente
          </Button>
        </div>
      )}

      {!isLoading && !error && documents.length === 0 && <p className={styles.empty}>{EMPTY_MESSAGE[context]}</p>}

      {!isLoading && !error && documents.length > 0 && (
        <ul className={styles.list}>
          {documents.map((item) => {
            const actions = documentActions(item);

            return (
              <li key={item.id} className={styles.item}>
                <div className={styles.itemMain}>
                  <div className={styles.itemTitleRow}>
                    <span className={styles.itemTitle}>{item.title}</span>
                    {/* Sem a contagem no badge: ela já aparece logo abaixo. */}
                    <DocumentStatusBadge document={item} showCount={false} />
                  </div>

                  <p className={styles.meta}>
                    <span>{item.type_label}</span>
                    <span className={item.status === 'partially_signed' ? styles.partial : undefined}>{signaturesText(item)}</span>
                    {item.sent_at && <span>Enviado em {formatDate(item.sent_at)}</span>}
                    {item.signed_at && <span>Finalizado em {formatDate(item.signed_at)}</span>}
                  </p>
                </div>

                <div className={styles.actions}>
                  <Button variant="ghost" onClick={() => setDetailsId(item.id)} disabled={busy} title={LABELS.view}>
                    {LABELS.view}
                  </Button>
                  {actions.canDownloadOriginal && (
                    <Button variant="ghost" onClick={() => quickActions.download(item, 'original')} isLoading={isBusy(item, 'original')} disabled={busy} title={LABELS.downloadOriginal}>
                      {isBusy(item, 'original') ? <Spinner size="sm" /> : <DownloadIcon width={15} height={15} />}
                      {LABELS.downloadOriginal}
                    </Button>
                  )}
                  {/* Aqui só enquanto há assinatura pendente; para finalizados a
                      sincronização segue como ação secundária na tabela e nos detalhes. */}
                  {actions.syncIsPrimary && (
                    <Button variant="ghost" onClick={() => quickActions.sync(item)} isLoading={isBusy(item, 'sync')} disabled={busy} title={LABELS.sync}>
                      {isBusy(item, 'sync') ? <Spinner size="sm" /> : <RefreshIcon width={15} height={15} />}
                      {LABELS.sync}
                    </Button>
                  )}
                  {/* Contrato finalizado: download em destaque (cor primária + ícone + rótulo). */}
                  {actions.canDownloadSigned && (
                    <Button onClick={() => quickActions.download(item, 'signed')} isLoading={isBusy(item, 'signed')} disabled={busy} title={LABELS.downloadSigned}>
                      {isBusy(item, 'signed') ? <Spinner size="sm" /> : <DownloadIcon width={15} height={15} />}
                      {LABELS.downloadSigned}
                    </Button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {!isLoading && !error && total > documents.length && (
        <p className={styles.more}>
          Mostrando {documents.length} de {total}. A lista completa está na aba Documentos.
        </p>
      )}

      {createPortal(
        <>
          <DocumentDetailsModal
            isOpen={detailsId !== null && !form.isOpen}
            documentId={detailsId}
            onClose={() => setDetailsId(null)}
            onEdit={(document) => setForm({ isOpen: true, document })}
            onChanged={replaceDocument}
            onDeleted={handleDeleted}
          />
          <DocumentFormModal
            isOpen={form.isOpen}
            document={form.document}
            initialValues={form.document ? null : initialValues}
            onClose={() => setForm({ isOpen: false, document: null })}
            onSaved={handleSaved}
          />
        </>,
        window.document.body,
      )}
    </section>
  );
}
