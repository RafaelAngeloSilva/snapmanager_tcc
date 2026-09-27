import Table, { TableHead, TableBody, TableRow, TableHeaderCell, TableCell } from '../../atoms/Table';
import Badge from '../../atoms/Badge';
import Button from '../../atoms/Button';
import { useAuth } from '../../../context/AuthContext';
import { EyeIcon, EditIcon, TrashIcon, UploadIcon, LockIcon, CopyIcon } from '../../atoms/Icon';
import { formatDateTime } from '../../../utils/formatters';
import {
  GALLERY_STATUS_LABELS,
  GALLERY_STATUS_BADGE_VARIANT,
  SELECTION_STATUS_LABELS,
  SELECTION_STATUS_BADGE_VARIANT,
  getSelectionStatus,
} from '../../../utils/galleryStatus';
import styles from './GalleriesTable.module.css';

export default function GalleriesTable({ galleries, onView, onEdit, onUpload, onCopyLink, onClose, onDelete }) {
  const { can } = useAuth();
  const canManage = can('galleries.manage');

  return (
    <Table>
      <TableHead>
        <TableRow>
          <TableHeaderCell>Galeria</TableHeaderCell>
          <TableHeaderCell>Cliente</TableHeaderCell>
          <TableHeaderCell>Fotos</TableHeaderCell>
          <TableHeaderCell>Escolha</TableHeaderCell>
          <TableHeaderCell>Expira em</TableHeaderCell>
          <TableHeaderCell>Status</TableHeaderCell>
          <TableHeaderCell align="right">Ações</TableHeaderCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {galleries.map((gallery) => {
          const selectionStatus = getSelectionStatus(gallery);

          return (
            <TableRow key={gallery.id} className={selectionStatus === 'completed' ? styles.rowCompleted : ''}>
              <TableCell>
                <div className={styles.titleCell}>
                  <span className={styles.titleRow}>
                    <span className={styles.title}>{gallery.title}</span>
                    {gallery.watermark_enabled && (
                      <LockIcon width={13} height={13} className={styles.watermarkIcon} aria-label="Marca d'água ativa" />
                    )}
                  </span>
                  {gallery.order && <span className={styles.orderLink}>Pedido: {gallery.order.title}</span>}
                </div>
              </TableCell>

              <TableCell>
                <span className={gallery.client ? styles.person : styles.muted}>
                  {gallery.client?.name || 'Cliente removido'}
                </span>
              </TableCell>

              <TableCell>
                <span className={styles.counts}>
                  {gallery.selected_count > 0 ? gallery.selected_count : 0} de {gallery.images_count ?? 0} foto(s)
                </span>
              </TableCell>

              <TableCell>
                <Badge variant={SELECTION_STATUS_BADGE_VARIANT[selectionStatus]}>
                  {SELECTION_STATUS_LABELS[selectionStatus]}
                </Badge>
              </TableCell>

              <TableCell>
                <span className={styles.muted}>{gallery.expires_at ? formatDateTime(gallery.expires_at) : '—'}</span>
              </TableCell>

              <TableCell>
                <Badge variant={GALLERY_STATUS_BADGE_VARIANT[gallery.status] || 'neutral'}>
                  {GALLERY_STATUS_LABELS[gallery.status] || gallery.status}
                </Badge>
              </TableCell>

              <TableCell align="right">
                <div className={styles.actions}>
                  <Button variant="ghost" size="icon" onClick={() => onView(gallery)} aria-label={`Ver detalhes de ${gallery.title}`}>
                    <EyeIcon />
                  </Button>
                  {canManage && (
                    <Button variant="ghost" size="icon" onClick={() => onUpload(gallery)} aria-label={`Enviar fotos para ${gallery.title}`}>
                      <UploadIcon />
                    </Button>
                  )}
                  <Button variant="ghost" size="icon" onClick={() => onCopyLink(gallery)} aria-label={`Copiar link público de ${gallery.title}`}>
                    <CopyIcon />
                  </Button>
                  {canManage && (
                    <Button variant="ghost" size="icon" onClick={() => onEdit(gallery)} aria-label={`Editar ${gallery.title}`}>
                      <EditIcon />
                    </Button>
                  )}
                  {canManage && gallery.status === 'active' && (
                    <Button variant="ghost" size="icon" onClick={() => onClose(gallery)} aria-label={`Encerrar ${gallery.title}`}>
                      <LockIcon />
                    </Button>
                  )}
                  {canManage && (
                    <Button variant="ghost" size="icon" onClick={() => onDelete(gallery)} aria-label={`Excluir ${gallery.title}`}>
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
