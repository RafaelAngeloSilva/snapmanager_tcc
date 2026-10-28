/**
 * Parâmetros e regras de reconhecimento de gesto do SnapSwipe — tudo aqui,
 * para ajustar a sensibilidade num lugar só. Distâncias em px, tempos em ms.
 */
export const SWIPE = Object.freeze({
  /** Até aqui o dedo "treme" sem efeito: o cartão nem se mexe. */
  DEAD_ZONE: 10,
  /** Toque: moveu menos que isto e soltou rápido → amplia a foto. */
  TAP_MAX_DISTANCE: 10,
  TAP_MAX_DURATION: 300,
  /**
   * Trava de eixo: o gesto só é horizontal se |dx| ≥ |dy| × este fator
   * (≈ até 35° da horizontal). Diagonal mais inclinada = rolagem vertical.
   */
  HORIZONTAL_RATIO: 1.4,
  /** Distância para decidir ao soltar: 32% da largura do cartão, entre 80 e 150 px. */
  THRESHOLD_RATIO: 0.32,
  THRESHOLD_MIN: 80,
  THRESHOLD_MAX: 150,
  /** "Peteleco": rápido o bastante decide com menos distância — mas nunca abaixo desta. */
  FLICK_MIN_VELOCITY: 0.65, // px/ms (~650 px/s)
  FLICK_MIN_DISTANCE: 55,
  /** Janela usada para medir a velocidade no fim do gesto. */
  VELOCITY_WINDOW: 100,
  /** A partir daqui aparece o selo "Selecionar"/"Passar" (já descontada a zona morta). */
  HINT_DISTANCE: 18,
});

export function decisionThreshold(width) {
  return Math.min(SWIPE.THRESHOLD_MAX, Math.max(SWIPE.THRESHOLD_MIN, (width || 320) * SWIPE.THRESHOLD_RATIO));
}

/**
 * Eixo do gesto depois que ele sai da zona morta: 'pending' (ainda dentro
 * dela), 'horizontal' ou 'vertical'.
 */
export function lockAxis(dx, dy) {
  if (Math.hypot(dx, dy) < SWIPE.DEAD_ZONE) return 'pending';

  return Math.abs(dx) >= Math.abs(dy) * SWIPE.HORIZONTAL_RATIO ? 'horizontal' : 'vertical';
}

/** Deslocamento visual do cartão: começa do zero ao sair da zona morta (sem "pulo"). */
export function visualOffset(dx) {
  if (Math.abs(dx) <= SWIPE.DEAD_ZONE) return 0;

  return dx - Math.sign(dx) * SWIPE.DEAD_ZONE;
}

/**
 * Velocidade horizontal (px/ms) nos últimos VELOCITY_WINDOW ms do gesto.
 * samples: [{ x, t }] em ordem.
 */
export function horizontalVelocity(samples) {
  if (!samples || samples.length < 2) return 0;

  const last = samples[samples.length - 1];
  const first = samples.find((sample) => last.t - sample.t <= SWIPE.VELOCITY_WINDOW) ?? samples[0];
  const elapsed = last.t - first.t;

  return elapsed > 0 ? (last.x - first.x) / elapsed : 0;
}

/**
 * O que fazer ao soltar o dedo: 'tap' | 'select' | 'skip' | 'none'.
 * axis: resultado de lockAxis durante o gesto.
 */
export function classifyRelease({ dx, dy, duration, velocity, width, axis }) {
  const distance = Math.hypot(dx, dy);

  if (distance < SWIPE.TAP_MAX_DISTANCE && duration < SWIPE.TAP_MAX_DURATION) return 'tap';
  if (axis !== 'horizontal') return 'none';
  // O gesto precisa terminar ainda predominantemente horizontal.
  if (Math.abs(dx) < Math.abs(dy) * SWIPE.HORIZONTAL_RATIO) return 'none';

  const passedDistance = Math.abs(dx) >= decisionThreshold(width);
  // Peteleco: rápido, na MESMA direção do deslocamento, com distância mínima.
  const isFlick =
    Math.abs(dx) >= SWIPE.FLICK_MIN_DISTANCE &&
    Math.abs(velocity) >= SWIPE.FLICK_MIN_VELOCITY &&
    Math.sign(velocity) === Math.sign(dx);

  if (!passedDistance && !isFlick) return 'none';

  return dx > 0 ? 'select' : 'skip';
}
