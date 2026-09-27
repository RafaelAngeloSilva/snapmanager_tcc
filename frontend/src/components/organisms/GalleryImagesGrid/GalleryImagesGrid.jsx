import { memo } from 'react';
import ImageThumb from '../../atoms/ImageThumb';
import EmptyState from '../../atoms/EmptyState';
import { CheckCircleIcon, TrashIcon } from '../../atoms/Icon';
import { formatPhotoNumber } from '../../../utils/photoNumber';
import styles from './GalleryImagesGrid.module.css';

const GalleryImageCard = memo(function GalleryImageCard({ image, onDelete }) {
  const photoNumber = formatPhotoNumber(image.display_number);

  return (
    <div className={`${styles.item} ${image.is_selected ? styles.selected : ''}`}>
      <ImageThumb src={image.thumbnail_url} alt={image.original_name} className={styles.thumb} />

      {photoNumber && <span className={styles.numberBadge}>{photoNumber}</span>}

      {image.is_selected && (
        <span className={styles.selectedBadge} title="Selecionada pelo cliente">
          <CheckCircleIcon width={14} height={14} />
        </span>
      )}

      <button
        type="button"
        className={styles.removeButton}
        onClick={() => onDelete(image)}
        aria-label={`Remover ${image.original_name}`}
      >
        <TrashIcon width={14} height={14} />
      </button>
    </div>
  );
});

/**
 * Grid interno (autenticado) das fotos de uma galeria. Fotos que o cliente
 * selecionou no link público ficam destacadas visualmente.
 */
export default function GalleryImagesGrid({ images, onDelete }) {
  if (!images || images.length === 0) {
    return (
      <EmptyState
        title="Nenhuma foto enviada ainda"
        message="Use o botão “Enviar fotos” para adicionar imagens a esta galeria."
      />
    );
  }

  return (
    <div className={styles.grid}>
      {images.map((image) => (
        <GalleryImageCard key={image.id} image={image} onDelete={onDelete} />
      ))}
    </div>
  );
}
