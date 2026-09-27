import { useCallback, useEffect, useRef, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Badge from '../../atoms/Badge';
import EmptyState from '../../atoms/EmptyState';
import ImageThumb from '../../atoms/ImageThumb';
import CopyLinkButton from '../../molecules/CopyLinkButton';
import WhatsAppShareButton from '../../molecules/WhatsAppShareButton';
import { UploadIcon } from '../../atoms/Icon';
import GalleryImagesGrid from '../GalleryImagesGrid';
import GalleryUploadModal from '../GalleryUploadModal';
import { fetchGallery, deleteGalleryImage, uploadGalleryWatermarkImage } from '../../../api/galleries';
import { formatDateTime } from '../../../utils/formatters';
import {
  GALLERY_STATUS_LABELS,
  GALLERY_STATUS_BADGE_VARIANT,
  SELECTION_STATUS_LABELS,
  SELECTION_STATUS_BADGE_VARIANT,
  getSelectionStatus,
} from '../../../utils/galleryStatus';
import { galleryShareMessage } from '../../../utils/whatsapp';
import styles from './GalleryDetailsModal.module.css';

const WATERMARK_TYPE_LABELS = {
  text: 'Texto genérico',
  image: 'Imagem personalizada',
  none: 'Nenhuma',
};

export default function GalleryDetailsModal({ isOpen, galleryId, onClose, onEdit, onCloseGallery, onChanged }) {
  const [gallery, setGallery] = useState(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState('');
  const [isUploadOpen, setIsUploadOpen] = useState(false);
  const [isWatermarkUploading, setIsWatermarkUploading] = useState(false);
  const [watermarkError, setWatermarkError] = useState('');
  const watermarkInputRef = useRef(null);

  function load() {
    if (!galleryId) return;

    setIsLoading(true);
    setError('');

    fetchGallery(galleryId)
      .then(setGallery)
      .catch(() => setError('Não foi possível carregar os detalhes da galeria.'))
      .finally(() => setIsLoading(false));
  }

  useEffect(() => {
    if (!isOpen || !galleryId) {
      setGallery(null);
      return;
    }

    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, galleryId]);

  function handleUploaded() {
    setIsUploadOpen(false);
    load();
    onChanged?.();
  }

  const loadedGalleryId = gallery?.id;
  const handleDeleteImage = useCallback(
    async (image) => {
      if (!window.confirm(`Remover a foto "${image.original_name}"?`)) return;

      await deleteGalleryImage(loadedGalleryId, image.id);
      load();
      onChanged?.();
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [loadedGalleryId, onChanged],
  );

  async function handleWatermarkImageSelected(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    setIsWatermarkUploading(true);
    setWatermarkError('');

    try {
      await uploadGalleryWatermarkImage(gallery.id, file);
      load();
    } catch (err) {
      const messages = Object.values(err.response?.data?.errors || {}).flat();
      setWatermarkError(messages[0] || 'Não foi possível enviar a imagem de marca d\'água.');
    } finally {
      setIsWatermarkUploading(false);
    }
  }

  const publicUrl = gallery ? `${window.location.origin}/public/gallery/${gallery.access_token}` : '';
  const totalCount = gallery?.images?.length || 0;
  const selectedCount = gallery?.images?.filter((image) => image.is_selected).length || 0;
  const selectionStatus = gallery ? getSelectionStatus(gallery) : 'pending';

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Detalhes da galeria"
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Fechar
          </Button>
          {gallery && gallery.status === 'active' && (
            <Button variant="secondary" onClick={() => onCloseGallery(gallery)}>
              Encerrar galeria
            </Button>
          )}
          {gallery && <Button onClick={() => onEdit(gallery)}>Editar</Button>}
        </>
      }
    >
      {isLoading && (
        <div className={styles.loadingBox}>
          <Spinner /> Carregando...
        </div>
      )}

      {!isLoading && error && <EmptyState tone="error" title="Erro" message={error} />}

      {!isLoading && !error && gallery && (
        <>
          <div className={styles.header}>
            <div>
              <h2 className={styles.title}>{gallery.title}</h2>
              <p className={styles.subtitle}>{gallery.client?.name || 'Cliente removido'}</p>
            </div>
            <Badge variant={GALLERY_STATUS_BADGE_VARIANT[gallery.status] || 'neutral'}>
              {GALLERY_STATUS_LABELS[gallery.status] || gallery.status}
            </Badge>
          </div>

          <div className={styles.selectionSummary}>
            <div className={styles.selectionSummaryStat}>
              <span className={styles.selectionSummaryValue}>
                {selectedCount} de {totalCount}
              </span>
              <span className={styles.selectionSummaryLabel}>fotos selecionadas</span>
            </div>
            <div className={styles.selectionSummaryStatusCol}>
              <Badge variant={SELECTION_STATUS_BADGE_VARIANT[selectionStatus]}>
                {SELECTION_STATUS_LABELS[selectionStatus]}
              </Badge>
              {gallery.selection_completed_at && (
                <span className={styles.selectionSummaryTimestamp}>
                  em {formatDateTime(gallery.selection_completed_at)}
                </span>
              )}
            </div>
          </div>

          <dl className={styles.details}>
            <div>
              <dt>Pedido relacionado</dt>
              <dd>{gallery.order?.title || '—'}</dd>
            </div>
            <div>
              <dt>Expira em</dt>
              <dd>{gallery.expires_at ? formatDateTime(gallery.expires_at) : 'Sem expiração definida'}</dd>
            </div>
            <div>
              <dt>Criado por</dt>
              <dd>{gallery.created_by?.name || '—'}</dd>
            </div>
            {gallery.description && (
              <div className={styles.fullWidth}>
                <dt>Descrição</dt>
                <dd>{gallery.description}</dd>
              </div>
            )}
          </dl>

          <div className={styles.watermarkStatusBox}>
            <div className={styles.watermarkStatusHeader}>
              <p className={styles.watermarkStatusTitle}>Marca d'água</p>
              <Badge variant={gallery.watermark_enabled ? 'success' : 'neutral'} dot={false}>
                {gallery.watermark_enabled ? 'Ativa' : 'Desativada'}
              </Badge>
            </div>

            {gallery.watermark_enabled && (
              <>
                <p className={styles.watermarkStatusMeta}>
                  Tipo: {WATERMARK_TYPE_LABELS[gallery.watermark_type] || gallery.watermark_type}
                  {gallery.watermark_type === 'text' && ` — "${gallery.watermark_text}"`}
                </p>
                <p className={styles.watermarkNotice}>
                  As imagens públicas desta galeria são exibidas com marca d'água.
                </p>
              </>
            )}

            {gallery.watermark_enabled && gallery.watermark_type === 'image' && (
              <div className={styles.watermarkImageRow}>
                {gallery.watermark_image_url ? (
                  <div className={styles.watermarkImageThumbWrap}>
                    <ImageThumb src={gallery.watermark_image_url} alt="Marca d'água personalizada" className={styles.watermarkImageThumb} />
                  </div>
                ) : (
                  <p className={styles.watermarkStatusMeta}>Nenhuma imagem enviada ainda.</p>
                )}

                <input
                  ref={watermarkInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  onChange={handleWatermarkImageSelected}
                  className={styles.hiddenInput}
                  id="gallery-watermark-image-input"
                />
                <Button
                  type="button"
                  variant="secondary"
                  isLoading={isWatermarkUploading}
                  onClick={() => watermarkInputRef.current?.click()}
                >
                  {isWatermarkUploading ? <Spinner size="sm" /> : gallery.watermark_image_url ? 'Trocar imagem' : 'Enviar imagem'}
                </Button>
              </div>
            )}

            {watermarkError && <p className={styles.watermarkError}>{watermarkError}</p>}
          </div>

          <div className={styles.linkBox}>
            <div className={styles.linkInfo}>
              <p className={styles.linkLabel}>Link público para o cliente</p>
              <p className={styles.linkValue}>{publicUrl}</p>
            </div>
            <div className={styles.linkActions}>
              <CopyLinkButton value={publicUrl} />
              <WhatsAppShareButton
                phone={gallery.client?.phone}
                message={galleryShareMessage({
                  clientName: gallery.client?.name,
                  title: gallery.title,
                  url: publicUrl,
                  expiresAt: gallery.expires_at,
                })}
              />
            </div>
          </div>

          <div className={styles.sectionHeader}>
            <p className={styles.sectionTitle}>Fotos</p>
            <Button variant="secondary" onClick={() => setIsUploadOpen(true)}>
              <UploadIcon />
              Enviar fotos
            </Button>
          </div>

          <GalleryImagesGrid images={gallery.images} onDelete={handleDeleteImage} />
        </>
      )}

      <GalleryUploadModal
        isOpen={isUploadOpen}
        gallery={gallery}
        onClose={() => setIsUploadOpen(false)}
        onUploaded={handleUploaded}
      />
    </Modal>
  );
}
