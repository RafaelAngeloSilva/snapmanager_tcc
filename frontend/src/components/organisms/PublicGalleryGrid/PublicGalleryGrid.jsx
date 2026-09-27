import ImageSelectionCard from '../../molecules/ImageSelectionCard';
import EmptyState from '../../atoms/EmptyState';
import styles from './PublicGalleryGrid.module.css';

export default function PublicGalleryGrid({ images, selectedIds, onToggle, onExpand, disabled }) {
  if (!images || images.length === 0) {
    return <EmptyState title="Nenhuma foto disponível" message="Ainda não há fotos nesta galeria." />;
  }

  return (
    <div className={styles.grid}>
      {images.map((image, index) => (
        <ImageSelectionCard
          key={image.id}
          image={image}
          index={index}
          selected={selectedIds.includes(image.id)}
          onToggle={onToggle}
          onExpand={onExpand}
          disabled={disabled}
        />
      ))}
    </div>
  );
}
