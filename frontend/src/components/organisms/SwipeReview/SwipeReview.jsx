import { useState } from 'react';
import Button from '../../atoms/Button';
import EmptyState from '../../atoms/EmptyState';
import { CheckIcon, CloseIcon, UndoIcon, ZoomInIcon } from '../../atoms/Icon';
import styles from './SwipeReview.module.css';

const FILTERS = [
  { value: 'selected', label: 'Selecionadas' },
  { value: 'unselected', label: 'Não selecionadas' },
  { value: 'all', label: 'Todas' },
];

const EMPTY = {
  selected: 'Você ainda não selecionou nenhuma foto.',
  unselected: 'Todas as fotos desta galeria estão selecionadas.',
  all: 'Nenhuma foto nesta galeria.',
};

/**
 * Revisão das decisões antes de finalizar: dá para trocar a seleção ali
 * mesmo ou voltar ao cartão de uma foto específica, sem recomeçar a
 * galeria inteira.
 */
export default function SwipeReview({ images, selectedIds, initialFilter = 'selected', onToggle, onJumpTo, onExpand, onClose, disabled }) {
  const [filter, setFilter] = useState(initialFilter);

  const visible = images
    .map((image, index) => ({ image, index }))
    .filter(({ image }) => {
      const isSelected = selectedIds.includes(image.id);

      return filter === 'all' || (filter === 'selected' ? isSelected : !isSelected);
    });

  return (
    <div className={styles.review}>
      <div className={styles.header}>
        <div className={styles.filters} role="group" aria-label="Filtrar fotos da revisão">
          {FILTERS.map((option) => (
            <button
              key={option.value}
              type="button"
              className={`${styles.filter} ${filter === option.value ? styles.filterActive : ''}`}
              onClick={() => setFilter(option.value)}
              aria-pressed={filter === option.value}
            >
              {option.label}
            </button>
          ))}
        </div>

        <Button variant="secondary" onClick={onClose}>
          Voltar ao SnapSwipe
        </Button>
      </div>

      {visible.length === 0 ? (
        <EmptyState title="Nada para revisar aqui" message={EMPTY[filter]} />
      ) : (
        <ul className={styles.list}>
          {visible.map(({ image, index }) => {
            const isSelected = selectedIds.includes(image.id);

            return (
              <li key={image.id} className={`${styles.item} ${isSelected ? styles.itemSelected : ''}`}>
                <img src={image.thumbnail_url} alt={`Foto ${image.display_number ?? index + 1}`} className={styles.thumb} loading="lazy" decoding="async" />

                <span className={styles.itemNumber}>Foto {image.display_number ?? index + 1}</span>

                <div className={styles.itemActions}>
                  <button type="button" className={styles.iconButton} onClick={() => onExpand(index)} aria-label={`Ampliar foto ${image.display_number ?? index + 1}`}>
                    <ZoomInIcon width={16} height={16} aria-hidden="true" />
                  </button>

                  <button
                    type="button"
                    className={styles.iconButton}
                    onClick={() => onJumpTo(index)}
                    aria-label={`Voltar ao cartão da foto ${image.display_number ?? index + 1}`}
                    title="Voltar a esta foto no SnapSwipe"
                  >
                    <UndoIcon width={16} height={16} aria-hidden="true" />
                  </button>

                  <button
                    type="button"
                    className={`${styles.toggle} ${isSelected ? styles.toggleSelected : ''}`}
                    onClick={() => onToggle(image.id)}
                    disabled={disabled}
                    aria-pressed={isSelected}
                    aria-label={isSelected ? `Remover foto ${image.display_number ?? index + 1} da seleção` : `Selecionar foto ${image.display_number ?? index + 1}`}
                  >
                    {isSelected ? <CheckIcon width={15} height={15} aria-hidden="true" /> : <CloseIcon width={15} height={15} aria-hidden="true" />}
                    {isSelected ? 'Selecionada' : 'Não selecionada'}
                  </button>
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
