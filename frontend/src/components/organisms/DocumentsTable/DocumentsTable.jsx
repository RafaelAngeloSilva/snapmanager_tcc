import Table, { TableHead, TableBody, TableRow, TableHeaderCell, TableCell } from '../../atoms/Table';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import DocumentStatusBadge from '../../atoms/DocumentStatusBadge';
import { DownloadIcon, EyeIcon, RefreshIcon } from '../../atoms/Icon';
import ActionMenu from '../../molecules/ActionMenu';
import { formatDate } from '../../../utils/formatters';
import { DOCUMENT_ACTION_LABELS as LABELS, SIGNER_CHANNEL_LABELS, documentActions } from '../../../utils/documents';
import styles from './DocumentsTable.module.css';

/**
 * Coluna Ações: "Visualizar" sempre visível; "Baixar assinado" em destaque
 * (ícone + rótulo) quando o documento está finalizado — o contrato pronto
 * sai com um clique, sem abrir modal; o resto vai no menu ⋯ com ícone e
 * rótulo. Quais ações existem vem de documentActions() (permissão do
 * back-end + status).
 */
export default function DocumentsTable({ documents, onView, onSync, onDownload, isBusy, busy = false }) {
  function menuItems(document) {
    const actions = documentActions(document);
    const items = [];

    if (actions.canSync) {
      items.push({ key: 'sync', label: LABELS.sync, icon: <RefreshIcon width={16} height={16} />, onSelect: () => onSync(document), loading: isBusy(document, 'sync'), disabled: busy });
    }
    if (actions.canDownloadOriginal) {
      items.push({ key: 'original', label: LABELS.downloadOriginal, icon: <DownloadIcon width={16} height={16} />, onSelect: () => onDownload(document, 'original'), loading: isBusy(document, 'original'), disabled: busy });
    }
    if (actions.canDownloadSigned) {
      items.push({ key: 'signed', label: LABELS.downloadSigned, icon: <DownloadIcon width={16} height={16} />, onSelect: () => onDownload(document, 'signed'), loading: isBusy(document, 'signed'), disabled: busy });
    }

    // Sem ação extra (ex.: fotógrafo/editor), o menu nem aparece.
    return items.length ? [{ key: 'view', label: LABELS.view, icon: <EyeIcon width={16} height={16} />, onSelect: () => onView(document) }, ...items] : [];
  }

  return (
    <Table>
      <TableHead>
        <TableRow>
          <TableHeaderCell>Documento</TableHeaderCell>
          <TableHeaderCell>Cliente</TableHeaderCell>
          <TableHeaderCell>Signatários</TableHeaderCell>
          <TableHeaderCell>Canal</TableHeaderCell>
          <TableHeaderCell>Status</TableHeaderCell>
          <TableHeaderCell>Criado em</TableHeaderCell>
          <TableHeaderCell>Enviado em</TableHeaderCell>
          <TableHeaderCell align="right">Ações</TableHeaderCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {documents.map((document) => {
          const actions = documentActions(document);
          const syncing = isBusy(document, 'sync');
          const downloadingSigned = isBusy(document, 'signed');

          return (
            <TableRow key={document.id}>
              <TableCell>
                <button type="button" className={styles.titleButton} onClick={() => onView(document)}>
                  <span className={styles.title}>{document.title}</span>
                  <span className={styles.type}>
                    {document.type_label}
                    {document.file?.type === 'docx' && ' · Word'}
                  </span>
                </button>
              </TableCell>

              <TableCell>
                <span className={document.client ? styles.person : styles.muted}>{document.client?.name || 'Cliente removido'}</span>
              </TableCell>

              <TableCell>
                {document.signers_count ? (
                  <span className={styles.signers} title={document.signers.map((s) => s.name).join(', ')}>
                    {document.signers_count === 1 ? document.signers[0].name : `${document.signers_count} signatários`}
                  </span>
                ) : (
                  <span className={styles.muted}>Nenhum</span>
                )}
              </TableCell>

              <TableCell>
                {document.channels?.length ? (
                  document.channels.map((channel) => SIGNER_CHANNEL_LABELS[channel] || channel).join(', ')
                ) : (
                  <span className={styles.muted}>—</span>
                )}
              </TableCell>

              <TableCell>
                <span className={styles.statusCell}>
                  <DocumentStatusBadge document={document} />
                  {syncing && (
                    <span className={styles.syncing} role="status">
                      <Spinner size="sm" /> sincronizando
                    </span>
                  )}
                </span>
              </TableCell>

              <TableCell>{formatDate(document.created_at)}</TableCell>
              <TableCell>{document.sent_at ? formatDate(document.sent_at) : <span className={styles.muted}>—</span>}</TableCell>

              <TableCell align="right">
                <div className={styles.actions}>
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => onView(document)}
                    aria-label={`${LABELS.view} ${document.title}`}
                    title={LABELS.view}
                  >
                    <EyeIcon />
                  </Button>

                  {actions.canDownloadSigned && (
                    <Button
                      className={styles.signedButton}
                      onClick={() => onDownload(document, 'signed')}
                      isLoading={downloadingSigned}
                      disabled={busy}
                      aria-label={`${LABELS.downloadSigned}: ${document.title}`}
                      title={LABELS.downloadSigned}
                    >
                      {downloadingSigned ? <Spinner size="sm" /> : <DownloadIcon width={15} height={15} />}
                      <span className={styles.signedLabel}>{LABELS.downloadSigned}</span>
                    </Button>
                  )}

                  <ActionMenu label={`Mais ações para ${document.title}`} items={menuItems(document)} />
                </div>
              </TableCell>
            </TableRow>
          );
        })}
      </TableBody>
    </Table>
  );
}
