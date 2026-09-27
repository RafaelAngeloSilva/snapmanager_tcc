import { useRef, useState } from 'react';
import styles from './SwipePhotoCard.module.css';

/** Distância máxima (px) e tempo para um toque contar como "toque", não arrasto. */
const TAP_DISTANCE = 6;
const TAP_DURATION = 300;

/** A partir de quanto o gesto vira decisão (limitado para telas grandes). */
function decisionThreshold(width) {
  return Math.min(130, Math.max(60, width * 0.28));
}

/**
 * Cartão de uma foto no SnapSwipe.
 *
 * Gestos com Pointer Events, então funciona igual com dedo, mouse e caneta.
 * `touch-action: pan-y` (no CSS) mantém a rolagem vertical da página
 * funcionando: só o movimento horizontal é tratado aqui. Um gesto que
 * começa vertical é abandonado, para não brigar com a rolagem.
 *
 * O cartão só decide ao SOLTAR e passando do limite — arrastar um pouco e
 * voltar não seleciona nada. Passar NUNCA apaga a foto: é só "não escolher
 * agora".
 */
export default function SwipePhotoCard({ image, position, total, isSelected, isTop, disabled, onDecide, onExpand }) {
  const cardRef = useRef(null);
  const gesture = useRef(null);
  const [offset, setOffset] = useState(0);
  const [exiting, setExiting] = useState(null);

  const threshold = decisionThreshold(cardRef.current?.offsetWidth ?? 320);
  const intent = Math.abs(offset) > 12 ? (offset > 0 ? 'select' : 'skip') : null;

  function finish(direction) {
    setExiting(direction);
    setOffset(0);

    // Deixa a animação de saída rodar antes de trocar a foto. Com
    // "movimento reduzido" a transição é desligada no CSS e o tempo aqui
    // vira só um respiro curto.
    window.setTimeout(() => {
      setExiting(null);
      onDecide(direction);
    }, 180);
  }

  function handlePointerDown(event) {
    if (disabled || !isTop || exiting || event.button > 0) return;

    gesture.current = { id: event.pointerId, x: event.clientX, y: event.clientY, at: Date.now(), active: true };
    event.currentTarget.setPointerCapture?.(event.pointerId);
  }

  function handlePointerMove(event) {
    const current = gesture.current;

    if (!current?.active || current.id !== event.pointerId) return;

    const dx = event.clientX - current.x;
    const dy = event.clientY - current.y;

    // Gesto claramente vertical: é rolagem da página, não decisão.
    if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 12) {
      gesture.current.active = false;
      setOffset(0);

      return;
    }

    setOffset(dx);
  }

  function handlePointerUp(event) {
    const current = gesture.current;
    gesture.current = null;

    event.currentTarget.releasePointerCapture?.(event.pointerId);

    if (!current?.active) {
      setOffset(0);

      return;
    }

    const dx = event.clientX - current.x;
    const isTap = Math.abs(dx) < TAP_DISTANCE && Date.now() - current.at < TAP_DURATION;

    if (isTap) {
      setOffset(0);
      // Toque simples amplia (avaliar detalhe), nunca seleciona.
      onExpand();

      return;
    }

    if (Math.abs(dx) >= threshold) {
      finish(dx > 0 ? 'select' : 'skip');

      return;
    }

    setOffset(0);
  }

  const rotation = offset * 0.04;
  const style = exiting
    ? { transform: `translate3d(${exiting === 'select' ? '120%' : '-120%'}, 0, 0) rotate(${exiting === 'select' ? 14 : -14}deg)`, opacity: 0 }
    : { transform: `translate3d(${offset}px, 0, 0) rotate(${rotation}deg)` };

  return (
    <article
      ref={cardRef}
      className={[styles.card, isTop ? styles.top : styles.behind, offset !== 0 ? styles.dragging : '', exiting ? styles.exiting : '']
        .filter(Boolean)
        .join(' ')}
      style={style}
      onPointerDown={handlePointerDown}
      onPointerMove={handlePointerMove}
      onPointerUp={handlePointerUp}
      onPointerCancel={handlePointerUp}
      role={isTop ? 'button' : undefined}
      tabIndex={isTop && !disabled ? 0 : -1}
      aria-label={isTop ? `Foto ${position} de ${total}. Toque para ampliar.` : undefined}
      onKeyDown={(event) => {
        if (isTop && (event.key === 'Enter' || event.key === ' ')) {
          event.preventDefault();
          onExpand();
        }
      }}
    >
      <img
        src={image.thumbnail_url}
        alt={`Foto ${image.display_number ?? position}`}
        className={styles.image}
        draggable={false}
        loading={isTop ? 'eager' : 'lazy'}
        decoding="async"
      />

      <span className={styles.number}>Foto {image.display_number ?? position}</span>

      {isSelected && !intent && (
        <span className={styles.alreadySelected} aria-hidden="true">
          Selecionada
        </span>
      )}

      {/* Indicadores do gesto: texto + ícone, não só cor. */}
      <span
        className={`${styles.stamp} ${styles.stampSelect}`}
        style={{ opacity: intent === 'select' ? Math.min(1, offset / threshold) : 0 }}
        aria-hidden="true"
      >
        ✓ Selecionar
      </span>
      <span
        className={`${styles.stamp} ${styles.stampSkip}`}
        style={{ opacity: intent === 'skip' ? Math.min(1, -offset / threshold) : 0 }}
        aria-hidden="true"
      >
        ✕ Passar
      </span>
    </article>
  );
}
