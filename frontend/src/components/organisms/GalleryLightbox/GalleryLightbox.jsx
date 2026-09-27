import { useEffect } from 'react';
import { CheckCircleIcon, ChevronLeftIcon, ChevronRightIcon } from '../../atoms/Icon';
import { formatPhotoNumber } from '../../../utils/photoNumber';
import styles from './GalleryLightbox.module.css';

/**
 * Visualização ampliada de uma foto da galeria pública. Reaproveita
 * exatamente a mesma URL de imagem já usada na grade (que já é a versão com
 * marca d'água, servida por PublicGalleryController::showImageFile) e o
 * mesmo estado de seleção do componente pai — não busca nem guarda nada
 * novo, só amplia o que já está carregado.
 */
export default function GalleryLightbox({ images, index, selectedIds, onToggle, onClose, onNavigate, disabled }) {
  const image = images[index];
  const isSelected = image ? selectedIds.includes(image.id) : false;
  const hasMultiple = images.length > 1;

  useEffect(() => {
    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        onClose();
      } else if (event.key === 'ArrowLeft' && hasMultiple) {
        onNavigate(-1);
      } else if (event.key === 'ArrowRight' && hasMultiple) {
        onNavigate(1);
      }
    }

    document.addEventListener('keydown', handleKeyDown);

    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [onClose, onNavigate, hasMultiple]);

  if (!image) {
    return null;
  }

  function handleOverlayClick(event) {
    if (event.target === event.currentTarget) {
      onClose();
    }
  }

  const photoNumber = formatPhotoNumber(image.display_number);

  return (
    <div
      className={styles.overlay}
      onMouseDown={handleOverlayClick}
      role="dialog"
      aria-modal="true"
      aria-label={photoNumber || 'Foto ampliada'}
    >
      <button type="button" className={styles.closeButton} onClick={onClose} aria-label="Fechar">
        ✕
      </button>

      {hasMultiple && (
        <button
          type="button"
          className={`${styles.navButton} ${styles.navPrev}`}
          onClick={() => onNavigate(-1)}
          aria-label="Foto anterior"
        >
          <ChevronLeftIcon width={22} height={22} />
        </button>
      )}

      <div className={styles.stage}>
        <img src={image.url} alt="" className={styles.image} />

        <div className={styles.caption}>
          <span className={styles.counter}>
            {photoNumber ? `${photoNumber} — ` : ''}
            {index + 1} de {images.length}
          </span>

          {disabled ? (
            <span className={styles.statusLabel}>{isSelected ? 'Foto selecionada' : 'Não selecionada'}</span>
          ) : (
            <button
              type="button"
              className={`${styles.selectButton} ${isSelected ? styles.selected : ''}`}
              onClick={() => onToggle(image.id)}
            >
              {isSelected ? (
                <>
                  <CheckCircleIcon width={15} height={15} /> Foto selecionada
                </>
              ) : (
                'Selecionar esta foto'
              )}
            </button>
          )}
        </div>
      </div>

      {hasMultiple && (
        <button
          type="button"
          className={`${styles.navButton} ${styles.navNext}`}
          onClick={() => onNavigate(1)}
          aria-label="Próxima foto"
        >
          <ChevronRightIcon width={22} height={22} />
        </button>
      )}
    </div>
  );
}
