import { CheckIcon, CloseIcon, UndoIcon, ZoomInIcon } from '../../atoms/Icon';
import styles from './SwipeActions.module.css';

/**
 * Botões do SnapSwipe — a alternativa aos gestos (nada aqui depende de
 * deslizar). Cada botão tem ícone E rótulo: a função não é comunicada só
 * pela cor.
 */
export default function SwipeActions({ onSkip, onSelect, onUndo, onExpand, canUndo, disabled }) {
  return (
    <div className={styles.actions}>
      <button type="button" className={styles.secondary} onClick={onUndo} disabled={!canUndo || disabled} aria-label="Desfazer última decisão">
        <UndoIcon width={18} height={18} aria-hidden="true" />
        <span className={styles.secondaryLabel}>Desfazer</span>
      </button>

      <button type="button" className={`${styles.main} ${styles.skip}`} onClick={onSkip} disabled={disabled} aria-label="Passar esta foto (não selecionar)">
        <CloseIcon width={22} height={22} aria-hidden="true" />
        Passar
      </button>

      <button type="button" className={`${styles.main} ${styles.select}`} onClick={onSelect} disabled={disabled} aria-label="Selecionar esta foto">
        <CheckIcon width={22} height={22} aria-hidden="true" />
        Selecionar
      </button>

      <button type="button" className={styles.secondary} onClick={onExpand} disabled={disabled} aria-label="Ampliar esta foto">
        <ZoomInIcon width={18} height={18} aria-hidden="true" />
        <span className={styles.secondaryLabel}>Ampliar</span>
      </button>
    </div>
  );
}
