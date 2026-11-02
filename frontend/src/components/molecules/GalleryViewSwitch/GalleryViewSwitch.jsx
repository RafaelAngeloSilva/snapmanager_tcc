import { useLayoutEffect, useRef, useState } from 'react';
import { GridIcon, SwipeIcon } from '../../atoms/Icon';
import styles from './GalleryViewSwitch.module.css';

const MODES = [
  { value: 'gallery', label: 'Galeria tradicional', icon: GridIcon, hint: 'Ver todas as fotos em grade' },
  { value: 'snapswipe', label: 'SnapSwipe', icon: SwipeIcon, hint: 'Escolher foto por foto, deslizando' },
];

/**
 * Alterna entre a galeria tradicional e o SnapSwipe. As duas visões usam as
 * mesmas fotos e a mesma seleção — trocar de modo não perde nada.
 */
export default function GalleryViewSwitch({ mode, onChange }) {
  // Cada opção tem a largura do próprio nome; a pílula mede a opção ativa
  // e desliza até ela (posição e largura).
  const buttons = useRef({});
  const [thumb, setThumb] = useState(null);

  useLayoutEffect(() => {
    function measure() {
      const active = buttons.current[mode];
      if (active) setThumb({ x: active.offsetLeft, width: active.offsetWidth });
    }

    measure();
    window.addEventListener('resize', measure);
    // As fontes podem terminar de carregar depois e mudar a largura do texto.
    document.fonts?.ready?.then(measure).catch(() => {});

    return () => window.removeEventListener('resize', measure);
  }, [mode]);

  return (
    <div className={styles.switch} role="group" aria-label="Modo de visualização da galeria" data-mode={mode}>
      {/* "Pílula" clara que desliza até o modo ativo. */}
      <span
        className={styles.thumb}
        aria-hidden="true"
        style={thumb ? { transform: `translateX(${thumb.x}px)`, width: `${thumb.width}px`, opacity: 1 } : undefined}
      />
      {MODES.map((option) => {
        const Icon = option.icon;
        const isActive = mode === option.value;

        return (
          <button
            key={option.value}
            ref={(element) => {
              buttons.current[option.value] = element;
            }}
            type="button"
            className={`${styles.option} ${isActive ? styles.optionActive : ''}`}
            onClick={() => onChange(option.value)}
            aria-pressed={isActive}
            title={option.hint}
          >
            <Icon width={17} height={17} aria-hidden="true" />
            <span className={styles.label}>{option.label}</span>
          </button>
        );
      })}
    </div>
  );
}
