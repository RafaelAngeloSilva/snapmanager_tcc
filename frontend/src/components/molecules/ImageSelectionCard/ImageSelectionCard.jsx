import { memo } from 'react';
import Checkbox from '../../atoms/Checkbox';
import { EyeIcon } from '../../atoms/Icon';
import { formatPhotoNumber } from '../../../utils/photoNumber';
import styles from './ImageSelectionCard.module.css';

function ImageSelectionCard({ image, index, selected, onToggle, onExpand, disabled }) {
  function handleToggle() {
    if (!disabled) onToggle(image.id);
  }

  function handleKeyDown(event) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      handleToggle();
    }
  }

  function handleExpandClick(event) {
    // Ampliar é uma ação independente do toggle de seleção do card — não
    // deve marcar/desmarcar a foto, só abrir o visualizador.
    event.stopPropagation();
    onExpand(index);
  }

  const photoNumber = formatPhotoNumber(image.display_number);

  return (
    <div
      role="button"
      tabIndex={disabled ? -1 : 0}
      className={[styles.card, selected ? styles.selected : '', disabled ? styles.disabled : '']
        .filter(Boolean)
        .join(' ')}
      onClick={handleToggle}
      onKeyDown={handleKeyDown}
    >
      {/* Grade usa a miniatura (bem menor); o lightbox, aberto via onExpand,
          é que usa image.url em tamanho real — ver GalleryLightbox. */}
      <img src={image.thumbnail_url} alt="" className={styles.image} loading="lazy" decoding="async" />
      <span className={styles.overlay} />
      {photoNumber && <span className={styles.numberBadge}>{photoNumber}</span>}
      <button
        type="button"
        className={styles.expandButton}
        onClick={handleExpandClick}
        aria-label={photoNumber ? `Ampliar ${photoNumber}` : 'Ampliar foto'}
      >
        <EyeIcon width={15} height={15} />
      </button>
      <Checkbox
        checked={selected}
        onChange={handleToggle}
        onClick={(event) => event.stopPropagation()}
        className={styles.checkbox}
        tabIndex={-1}
        disabled={disabled}
      />
    </div>
  );
}

export default memo(ImageSelectionCard);
