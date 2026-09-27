import { useCallback, useEffect, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import DocumentStatusBadge from '../../atoms/DocumentStatusBadge';
import { DownloadIcon, PlusIcon, RefreshIcon } from '../../atoms/Icon';
import InlineAlert from '../../molecules/InlineAlert';
import SignerRow from '../../molecules/SignerRow';
import SignerFields from '../../molecules/SignerFields';
import DocumentTimeline from '../DocumentTimeline';
import SendDocumentModal from '../SendDocumentModal';
import ConfirmActionDialog from '../ConfirmActionDialog';
import { EMPTY_SIGNER } from '../SignersForm';
import {
  addDocumentSigner,
  cancelDocument,
  deleteDocument,
  discardFailedSend,
  fetchDocument,
  notifyDocument,
  removeDocumentSigner,
  retryConversion,
  sendDocument,
  syncDocument,
} from '../../../api/documents';
import { formatDateTime, formatDueDate } from '../../../utils/formatters';
import {
  DOCUMENT_ACTION_LABELS as LABELS,
  apiErrorMessage,
  documentActions,
  downloadErrorMessage,
  fieldErrors,
  formatFileSize,
  previewSigningPdf,
  saveDocumentFile,
} from '../../../utils/documents';
import styles from './DocumentDetailsModal.module.css';

/**
 * Detalhes + todas as ações do documento. Quais botões aparecem vem de
 * `document.abilities`, calculado pelo back-end (permissão + estado); o
 * back-end confere de novo em cada chamada.
 */
export default function DocumentDetailsModal({ isOpen, documentId, onClose, onEdit, onChanged, onDeleted }) {
  const [document, setDocument] = useState(null);
  const [isLoading, setIsLoading] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [notice, setNotice] = useState(null);
  const [busy, setBusy] = useState(''); // ação em andamento — bloqueia duplo clique

  const [confirm, setConfirm] = useState(null); // { kind, ... }
  const [confirmError, setConfirmError] = useState('');
  const [isSendOpen, setIsSendOpen] = useState(false);
  const [sendError, setSendError] = useState('');

  const [newSigner, setNewSigner] = useState(null);
  const [newSignerErrors, setNewSignerErrors] = useState({});

  const load = useCallback(() => {
    if (!documentId) return;

    setIsLoading(true);
    setLoadError('');

    fetchDocument(documentId)
      .then(setDocument)
      .catch((err) => setLoadError(apiErrorMessage(err, 'Não foi possível carregar o documento.')))
      .finally(() => setIsLoading(false));
  }, [documentId]);

  useEffect(() => {
    if (!isOpen) return;

    setDocument(null);
    setNotice(null);
    setNewSigner(null);
    setConfirm(null);
    setIsSendOpen(false);
    load();
  }, [isOpen, load]);

  function apply(updated, message, tone = 'success') {
    if (updated) {
      setDocument(updated);
      onChanged?.(updated);
    }

    if (message) setNotice({ tone, message });
  }

  /** Roda uma ação que devolve { message, data } e trata erro sem perder o estado atual. */
  async function runAction(name, call) {
    if (busy) return;

    setBusy(name);
    setNotice(null);

    try {
      const result = await call();
      apply(result.data, result.message);

      return true;
    } catch (err) {
      // Em falha da Clicksign o back-end manda o documento atualizado
      // (ex.: agora em "Erro de integração") junto com a mensagem.
      apply(err.response?.data?.data, apiErrorMessage(err), 'error');

      return false;
    } finally {
      setBusy('');
    }
  }

  async function handleSend() {
    setSendError('');
    setBusy('send');

    try {
      const result = await sendDocument(document.id);
      setIsSendOpen(false);
      apply(result.data, result.message);
    } catch (err) {
      if (err.response?.data?.data) apply(err.response.data.data);
      setSendError(apiErrorMessage(err));
    } finally {
      setBusy('');
    }
  }

  async function handleConfirm() {
    const { kind } = confirm;
    setConfirmError('');

    if (kind === 'delete') {
      setBusy('delete');

      try {
        await deleteDocument(document.id);
        setConfirm(null);
        onDeleted?.(document);
      } catch (err) {
        setConfirmError(apiErrorMessage(err));
      } finally {
        setBusy('');
      }

      return;
    }

    // Sucesso ou falha, o resultado aparece no aviso dos detalhes.
    const calls = { notify: notifyDocument, cancel: cancelDocument, discard: discardFailedSend };
    await runAction(kind, () => calls[kind](document.id));

    setConfirm(null);
  }

  async function handleDownload(kind) {
    if (busy) return;

    setBusy(`download-${kind}`);
    setNotice(null);

    try {
      await saveDocumentFile(document, kind);

      // O primeiro download do assinado grava um evento no histórico.
      if (kind === 'signed') fetchDocument(document.id).then(setDocument).catch(() => {});
    } catch (err) {
      setNotice({ tone: 'error', message: await downloadErrorMessage(err, 'Não foi possível baixar o arquivo.') });
    } finally {
      setBusy('');
    }
  }

  /** Abre o PDF que será assinado numa nova aba, para revisar a conversão. */
  async function handlePreview() {
    if (busy) return;

    setBusy('preview');
    setNotice(null);

    try {
      await previewSigningPdf(document);
    } catch (err) {
      setNotice({ tone: 'error', message: await downloadErrorMessage(err, 'Não foi possível abrir o PDF.') });
    } finally {
      setBusy('');
    }
  }

  async function handleAddSigner() {
    if (busy) return;

    setBusy('add-signer');
    setNewSignerErrors({});

    try {
      const updated = await addDocumentSigner(document.id, {
        name: newSigner.name,
        channel: newSigner.channel,
        email: newSigner.channel === 'email' ? newSigner.email : undefined,
        phone_number: newSigner.channel === 'whatsapp' ? newSigner.phone_number : undefined,
      });
      setNewSigner(null);
      apply(updated, 'Signatário adicionado.');
    } catch (err) {
      if (err.response?.status === 422) {
        setNewSignerErrors(fieldErrors(err));
      } else {
        setNotice({ tone: 'error', message: apiErrorMessage(err) });
      }
    } finally {
      setBusy('');
    }
  }

  async function handleRemoveSigner(signer) {
    if (busy) return;

    setBusy(`remove-${signer.id}`);

    try {
      apply(await removeDocumentSigner(document.id, signer.id), 'Signatário removido.');
    } catch (err) {
      setNotice({ tone: 'error', message: apiErrorMessage(err) });
    } finally {
      setBusy('');
    }
  }

  const can = document?.abilities || {};
  const signingFile = document?.signing_file;
  // Motivo da última falha de conversão (mensagem amigável do back-end).
  const lastConversionError = document?.events?.find((event) => event.event === 'conversion_failed')?.description;
  // Mesmas regras de visibilidade/destaque da tabela e dos documentos vinculados.
  const quick = document ? documentActions(document) : {};
  const hasOverlay = Boolean(confirm) || isSendOpen;
  const notifyLabel = document?.last_notified_at ? 'Reenviar solicitação' : 'Enviar solicitação';

  const CONFIRMS = document
    ? {
        notify: {
          title: notifyLabel,
          message: `Os signatários pendentes receberão o convite pelo canal escolhido (e-mail ou WhatsApp).`,
          hint: document.last_notified_at ? `Última solicitação: ${formatDateTime(document.last_notified_at)}.` : null,
          confirmLabel: notifyLabel,
          tone: 'primary',
        },
        cancel: {
          title: 'Cancelar processo de assinatura',
          message: `Tem certeza que deseja cancelar a assinatura de "${document.title}"?`,
          hint: 'O cancelamento é feito na Clicksign e não pode ser desfeito. Assinaturas já feitas perdem a validade neste processo.',
          confirmLabel: 'Cancelar processo',
        },
        delete: {
          title: 'Excluir rascunho',
          message: `Tem certeza que deseja excluir o rascunho "${document.title}"?`,
          hint: 'Nada foi enviado à Clicksign. O registro sai da listagem (exclusão lógica).',
          confirmLabel: 'Excluir',
        },
        discard: {
          title: 'Descartar envio com falha',
          message: 'O envelope incompleto será excluído na Clicksign e o documento voltará a ser rascunho.',
          hint: 'Depois disso você pode corrigir o problema e enviar de novo. O histórico do erro é mantido.',
          confirmLabel: 'Descartar e voltar a rascunho',
        },
      }
    : {};

  const footer = document && (
    <div className={styles.footer}>
      <div className={styles.footerSide}>
        {can.delete && (
          <Button variant="danger" onClick={() => setConfirm({ kind: 'delete' })} disabled={Boolean(busy)}>
            Excluir
          </Button>
        )}
        {can.cancel && (
          <Button variant="danger" onClick={() => setConfirm({ kind: 'cancel' })} disabled={Boolean(busy)}>
            Cancelar processo
          </Button>
        )}
        {can.discard_failed_send && (
          <Button variant="danger" onClick={() => setConfirm({ kind: 'discard' })} disabled={Boolean(busy)}>
            Descartar envio
          </Button>
        )}
      </div>

      <div className={styles.footerSide}>
        <Button variant="secondary" onClick={onClose} disabled={busy === 'send'}>
          Fechar
        </Button>
        {can.update && (
          <Button variant="secondary" onClick={() => onEdit(document)} disabled={Boolean(busy)}>
            Editar
          </Button>
        )}
        {can.notify && (
          <Button onClick={() => setConfirm({ kind: 'notify' })} disabled={Boolean(busy)}>
            {notifyLabel}
          </Button>
        )}
        {can.send && (
          <Button
            onClick={() => {
              setSendError('');
              setIsSendOpen(true);
            }}
            disabled={Boolean(busy) || document.signers.length === 0}
            title={document.signers.length === 0 ? 'Adicione ao menos um signatário' : undefined}
          >
            Enviar para assinatura
          </Button>
        )}
      </div>
    </div>
  );

  return (
    <>
      <Modal
        isOpen={isOpen}
        // Com um diálogo por cima, Esc/clique fora não fecham os detalhes junto.
        onClose={hasOverlay || busy === 'send' ? undefined : onClose}
        title="Detalhes do documento"
        size="lg"
        footer={footer}
      >
        {isLoading && !document && (
          <div className={styles.loadingBox}>
            <Spinner /> Carregando...
          </div>
        )}

        {!isLoading && loadError && <EmptyState tone="error" title="Erro" message={loadError} />}

        {document && (
          <div className={styles.content}>
            <div className={styles.header}>
              <div>
                <h2 className={styles.title}>{document.title}</h2>
                <p className={styles.subtitle}>{document.type_label}</p>
              </div>
              {/* Status + sincronização juntos: é aqui que se olha para saber
                  "em que pé está" e atualizar. Em destaque enquanto há
                  assinatura pendente; discreta depois de finalizado. */}
              <div className={styles.statusBox}>
                <DocumentStatusBadge document={document} />
                {quick.canSync && (
                  <Button
                    variant={quick.syncIsPrimary ? 'secondary' : 'ghost'}
                    onClick={() => runAction('sync', () => syncDocument(document.id))}
                    isLoading={busy === 'sync'}
                    disabled={Boolean(busy)}
                    title={LABELS.sync}
                  >
                    {busy === 'sync' ? <Spinner size="sm" /> : <RefreshIcon width={15} height={15} />}
                    {LABELS.sync}
                  </Button>
                )}
              </div>
            </div>

            {notice && (
              <InlineAlert tone={notice.tone} onDismiss={() => setNotice(null)}>
                {notice.message}
              </InlineAlert>
            )}

            {document.integration_error && (
              <div className={styles.integrationError} role="alert">
                <p className={styles.integrationErrorTitle}>O último envio parou na etapa “{document.integration_error.step}”.</p>
                <p>{document.integration_error.message}</p>
                <p className={styles.integrationErrorHint}>
                  Use <strong>Sincronizar status</strong> para conferir o estado real na Clicksign ou <strong>Descartar envio</strong> para voltar a
                  rascunho e tentar de novo.
                </p>
              </div>
            )}

            {document.status === 'awaiting_signature' && !document.last_notified_at && (
              <InlineAlert tone="info">
                O envelope está ativo na Clicksign, mas a solicitação ainda não foi enviada. Clique em <strong>Enviar solicitação</strong>.
              </InlineAlert>
            )}

            <section className={styles.files} aria-labelledby="document-files-title">
              <h3 id="document-files-title" className={styles.sectionTitle}>
                Arquivos
              </h3>

              {/* ORIGINAL: o arquivo enviado (PDF ou Word). Se já é PDF, é
                  ele mesmo que será assinado — um cartão só, sem duplicar. */}
              <div className={styles.fileCard}>
                <div className={styles.fileInfo}>
                  <span className={styles.fileKind}>Original · {document.file.type_label || 'PDF'}</span>
                  <span className={styles.fileName}>{document.file.name}</span>
                  <span className={styles.fileMeta}>
                    {formatFileSize(document.file.size)}
                    {signingFile?.same_as_original && ' · este é o PDF que será assinado'}
                  </span>
                </div>
                <div className={styles.fileActions}>
                  {signingFile?.same_as_original && can.download_signing_pdf && (
                    <Button variant="ghost" onClick={handlePreview} isLoading={busy === 'preview'} disabled={Boolean(busy)}>
                      {busy === 'preview' ? <Spinner size="sm" /> : null}
                      {LABELS.previewSigning}
                    </Button>
                  )}
                  {quick.canDownloadOriginal && (
                    <Button variant="secondary" onClick={() => handleDownload('original')} isLoading={busy === 'download-original'} disabled={Boolean(busy)}>
                      {busy === 'download-original' ? <Spinner size="sm" /> : <DownloadIcon width={15} height={15} />}
                      {LABELS.downloadOriginal}
                    </Button>
                  )}
                </div>
              </div>

              {/* PDF PARA ASSINATURA: só aparece separado quando o original é Word. */}
              {signingFile && !signingFile.same_as_original && (
                <div className={`${styles.fileCard} ${signingFile.ready ? '' : styles.fileCardWarning}`}>
                  <div className={styles.fileInfo}>
                    <span className={styles.fileKind}>PDF para assinatura · {document.conversion?.label}</span>
                    {signingFile.ready ? (
                      <>
                        <span className={styles.fileName}>{signingFile.name}</span>
                        <span className={styles.fileMeta}>
                          {formatFileSize(signingFile.size)} · convertido do Word — confira antes de enviar
                        </span>
                      </>
                    ) : (
                      <>
                        <span className={styles.fileName}>O PDF ainda não foi gerado</span>
                        <span className={styles.fileMeta}>
                          {lastConversionError || 'A conversão do Word não foi concluída.'} Enquanto isso, o documento não pode ser enviado para
                          assinatura.
                        </span>
                      </>
                    )}
                  </div>
                  <div className={styles.fileActions}>
                    {signingFile.ready && can.download_signing_pdf && (
                      <>
                        <Button variant="secondary" onClick={handlePreview} isLoading={busy === 'preview'} disabled={Boolean(busy)}>
                          {busy === 'preview' ? <Spinner size="sm" /> : null}
                          {LABELS.previewSigning}
                        </Button>
                        <Button variant="secondary" onClick={() => handleDownload('signing')} isLoading={busy === 'download-signing'} disabled={Boolean(busy)}>
                          {busy === 'download-signing' ? <Spinner size="sm" /> : <DownloadIcon width={15} height={15} />}
                          {LABELS.downloadSigning}
                        </Button>
                      </>
                    )}
                    {can.retry_conversion && (
                      <Button onClick={() => runAction('convert', () => retryConversion(document.id))} isLoading={busy === 'convert'} disabled={Boolean(busy)}>
                        {busy === 'convert' ? <Spinner size="sm" /> : <RefreshIcon width={15} height={15} />}
                        {LABELS.retryConversion}
                      </Button>
                    )}
                  </div>
                </div>
              )}

              {document.status === 'signed' ? (
                <div className={`${styles.fileCard} ${styles.fileCardSigned}`}>
                  <div className={styles.fileInfo}>
                    <span className={styles.fileKind}>Assinado</span>
                    <span className={styles.fileName}>Documento final com as assinaturas</span>
                    {document.signed_at && <span className={styles.fileMeta}>Finalizado em {formatDateTime(document.signed_at)}</span>}
                  </div>
                  {quick.canDownloadSigned && (
                    <Button onClick={() => handleDownload('signed')} isLoading={busy === 'download-signed'} disabled={Boolean(busy)}>
                      {busy === 'download-signed' ? <Spinner size="sm" /> : <DownloadIcon width={15} height={15} />}
                      {LABELS.downloadSigned}
                    </Button>
                  )}
                </div>
              ) : (
                ['awaiting_signature', 'partially_signed'].includes(document.status) && (
                  <p className={styles.fileHint}>O PDF assinado fica disponível aqui quando todas as assinaturas forem concluídas.</p>
                )
              )}
            </section>

            <dl className={styles.details}>
              <div>
                <dt>Cliente</dt>
                <dd>{document.client?.name || 'Cliente removido'}</dd>
              </div>
              <div>
                <dt>Pedido</dt>
                <dd>{document.order?.title || '—'}</dd>
              </div>
              {'appointment' in document && (
                <div>
                  <dt>Agendamento</dt>
                  <dd>
                    {document.appointment ? `${document.appointment.title} — ${formatDateTime(document.appointment.starts_at)}` : '—'}
                  </dd>
                </div>
              )}
              <div>
                <dt>Prazo para assinatura</dt>
                <dd>{document.expires_at ? formatDueDate(document.expires_at) : 'Padrão da Clicksign'}</dd>
              </div>
              <div>
                <dt>Criado por</dt>
                <dd>
                  {document.creator?.name || '—'} · {formatDateTime(document.created_at)}
                </dd>
              </div>
              <div>
                <dt>Enviado em</dt>
                <dd>{document.sent_at ? formatDateTime(document.sent_at) : '—'}</dd>
              </div>
              <div>
                <dt>Última solicitação</dt>
                <dd>{document.last_notified_at ? formatDateTime(document.last_notified_at) : '—'}</dd>
              </div>
              <div>
                <dt>Finalizado em</dt>
                <dd>{document.signed_at ? formatDateTime(document.signed_at) : '—'}</dd>
              </div>
              {document.notes && (
                <div className={styles.fullWidth}>
                  <dt>Observações</dt>
                  <dd className={styles.notes}>{document.notes}</dd>
                </div>
              )}
            </dl>

            <section>
              <h3 className={styles.sectionTitle}>
                Signatários <span className={styles.count}>{document.signed_count} de {document.signers_count} assinaram</span>
              </h3>

              {document.signers.length === 0 && <p className={styles.muted}>Nenhum signatário. Adicione ao menos um para enviar.</p>}

              <ul className={styles.signers}>
                {document.signers.map((signer) => (
                  <SignerRow
                    key={signer.id}
                    signer={signer}
                    onRemove={can.manage_signers ? handleRemoveSigner : undefined}
                    isRemoving={busy === `remove-${signer.id}`}
                  />
                ))}
              </ul>

              {can.manage_signers && !newSigner && (
                <Button variant="secondary" onClick={() => setNewSigner({ ...EMPTY_SIGNER })} disabled={Boolean(busy)}>
                  <PlusIcon />
                  Adicionar signatário
                </Button>
              )}

              {newSigner && (
                <div className={styles.newSigner}>
                  <SignerFields index={0} signer={newSigner} errors={newSignerErrors} onChange={setNewSigner} canRemove={false} />
                  <div className={styles.newSignerActions}>
                    <Button variant="secondary" onClick={() => setNewSigner(null)} disabled={busy === 'add-signer'}>
                      Cancelar
                    </Button>
                    <Button onClick={handleAddSigner} isLoading={busy === 'add-signer'}>
                      {busy === 'add-signer' ? <Spinner size="sm" /> : 'Adicionar'}
                    </Button>
                  </div>
                </div>
              )}
            </section>

            <section>
              <h3 className={styles.sectionTitle}>Histórico</h3>
              <DocumentTimeline events={document.events} />
            </section>

            {document.technical && (
              <details className={styles.technical}>
                <summary>Dados técnicos (Clicksign)</summary>
                <dl>
                  <div>
                    <dt>Envelope</dt>
                    <dd>{document.technical.clicksign_envelope_id || '—'}</dd>
                  </div>
                  <div>
                    <dt>Documento</dt>
                    <dd>{document.technical.clicksign_document_id || '—'}</dd>
                  </div>
                </dl>
              </details>
            )}
          </div>
        )}
      </Modal>

      <SendDocumentModal
        isOpen={isSendOpen}
        document={document}
        isSending={busy === 'send'}
        error={sendError}
        onConfirm={handleSend}
        onClose={() => setIsSendOpen(false)}
      />

      {confirm && (
        <ConfirmActionDialog
          isOpen
          {...CONFIRMS[confirm.kind]}
          isRunning={busy === confirm.kind}
          error={confirmError}
          onConfirm={handleConfirm}
          onClose={() => {
            setConfirm(null);
            setConfirmError('');
          }}
        />
      )}
    </>
  );
}
