import { GridIcon, SwipeIcon } from '../../atoms/Icon';
import styles from './GalleryViewSwitch.module.css';

const MODES = [
  { value: 'gallery', label: 'Galeria', icon: GridIcon, hint: 'Ver todas as fotos em grade' },
  { value: 'snapswipe', label: 'SnapSwipe', icon: SwipeIcon, hint: 'Escolher foto por foto, deslizando' },
];

/**
 * Alterna entre a galeria tradicional e o SnapSwipe. As duas visões usam as
 * mesmas fotos e a mesma seleção — trocar de modo não perde nada.
 */
export default function GalleryViewSwitch({ mode, onChange }) {
  return (
    <div className={styles.switch} role="group" aria-label="Modo de visualização da galeria">
      {MODES.map((option) => {
        const Icon = option.icon;
        const isActive = mode === option.value;

        return (
          <button
            key={option.value}
            type="button"
            className={`${styles.option} ${isActive ? styles.optionActive : ''}`}
            onClick={() => onChange(option.value)}
            aria-pressed={isActive}
            title={option.hint}
          >
            <Icon width={16} height={16} aria-hidden="true" />
            {option.label}
          </button>
        );
      })}
    </div>
  );
}
