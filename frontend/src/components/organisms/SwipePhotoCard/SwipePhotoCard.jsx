import { useRef, useState } from 'react';
import { SWIPE, classifyRelease, decisionThreshold, horizontalVelocity, lockAxis, visualOffset } from '../../../utils/swipeGesture';
import styles from './SwipePhotoCard.module.css';

/**
 * Cartão de uma foto no SnapSwipe.
 *
 * Gestos com Pointer Events, então funciona igual com dedo, mouse e caneta.
 * `touch-action: pan-y` (no CSS) mantém a rolagem vertical da página
 * funcionando. A sensibilidade (zona morta, trava de eixo, distância e
 * "peteleco") está toda em utils/swipeGesture.js:
 *  - tremidas de até SWIPE.DEAD_ZONE px não mexem o cartão;
 *  - ao sair da zona morta o eixo é travado: inclinado demais = rolagem,
 *    e o cartão é abandonado;
 *  - só decide ao SOLTAR: passou do limite de distância, ou foi um
 *    peteleco rápido com distância mínima, na mesma direção.
 * Passar NUNCA apaga a foto: é só "não escolher agora".
 */
// set/releasePointerCapture lançam erro quando o navegador já liberou o
// ponteiro (ex.: o dedo saiu da tela durante uma rolagem). Isso nunca pode
// interromper o reconhecimento do gesto.
function safePointerCapture(event, action) {
  try {
    if (action === 'set') event.currentTarget.setPointerCapture?.(event.pointerId);
    else event.currentTarget.releasePointerCapture?.(event.pointerId);
  } catch {
    // ponteiro já liberado: nada a fazer
  }
}

export default function SwipePhotoCard({ image, position, total, isSelected, isTop, disabled, onDecide, onExpand }) {
  const cardRef = useRef(null);
  const gesture = useRef(null);
  // Garante uma única decisão por gesto (pointerup + pointercancel, toques
  // repetidos durante a animação de saída...).
  const deciding = useRef(false);
  const [offset, setOffset] = useState(0);
  const [exiting, setExiting] = useState(null);

  const threshold = decisionThreshold(cardRef.current?.offsetWidth);
  const intent = Math.abs(offset) > SWIPE.HINT_DISTANCE ? (offset > 0 ? 'select' : 'skip') : null;

  function finish(direction) {
    if (deciding.current) return;
    deciding.current = true;

    setExiting(direction);
    setOffset(0);

    // Deixa a animação de saída rodar antes de trocar a foto. Com
    // "movimento reduzido" a transição é desligada no CSS e o tempo aqui
    // vira só um respiro curto.
    window.setTimeout(() => {
      setExiting(null);
      deciding.current = false;
      onDecide(direction);
    }, 180);
  }

  function handlePointerDown(event) {
    if (disabled || !isTop || exiting || deciding.current || event.button > 0) return;

    const now = event.timeStamp || performance.now();

    gesture.current = {
      id: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      at: now,
      axis: 'pending',
      samples: [{ x: event.clientX, t: now }],
    };
    safePointerCapture(event, 'set');
  }

  function handlePointerMove(event) {
    const current = gesture.current;

    if (!current || current.id !== event.pointerId || current.axis === 'vertical') return;

    const dx = event.clientX - current.x;
    const dy = event.clientY - current.y;
    const now = event.timeStamp || performance.now();

    current.samples.push({ x: event.clientX, t: now });
    if (current.samples.length > 20) current.samples.shift();

    if (current.axis === 'pending') {
      current.axis = lockAxis(dx, dy);
    }

    // Gesto inclinado demais: é rolagem da página, não decisão.
    if (current.axis === 'vertical') {
      setOffset(0);

      return;
    }

    setOffset(current.axis === 'horizontal' ? visualOffset(dx) : 0);
  }

  function handlePointerUp(event) {
    const current = gesture.current;
    gesture.current = null;

    safePointerCapture(event, 'release');

    if (!current || current.id !== event.pointerId) {
      setOffset(0);

      return;
    }

    // Cancelado pelo navegador (ex.: virou rolagem): nunca decide nem amplia.
    if (event.type === 'pointercancel') {
      setOffset(0);

      return;
    }

    const now = event.timeStamp || performance.now();
    current.samples.push({ x: event.clientX, t: now });

    const result = classifyRelease({
      dx: event.clientX - current.x,
      dy: event.clientY - current.y,
      duration: now - current.at,
      velocity: horizontalVelocity(current.samples),
      width: cardRef.current?.offsetWidth,
      axis: current.axis,
    });

    if (result === 'tap') {
      setOffset(0);
      // Toque simples amplia (avaliar detalhe), nunca seleciona.
      onExpand();

      return;
    }

    if (result === 'select' || result === 'skip') {
      finish(result);

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
