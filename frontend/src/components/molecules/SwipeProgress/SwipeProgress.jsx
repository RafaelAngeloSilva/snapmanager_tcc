import styles from './SwipeProgress.module.css';

/**
 * Progresso do SnapSwipe. Separa duas contagens que são fáceis de
 * confundir: quantas fotos já foram AVALIADAS (passaram pelo cartão) e
 * quantas foram ESCOLHIDAS. `limit` é opcional — hoje a galeria não tem
 * limite de fotos; se passar a ter, a contagem aparece aqui.
 */
export default function SwipeProgress({ position, total, evaluated, selected, limit = null }) {
  const remaining = Math.max(0, total - evaluated);
  const percent = total === 0 ? 0 : Math.round((evaluated / total) * 100);
  const selectedLabel = limit
    ? `${selected} de ${limit} ${limit === 1 ? 'foto selecionada' : 'fotos selecionadas'}`
    : `${selected} ${selected === 1 ? 'selecionada' : 'selecionadas'}`;
  const remainingLabel = remaining === 0 ? 'Todas avaliadas' : `${remaining} ${remaining === 1 ? 'restante' : 'restantes'} para avaliar`;

  return (
    <div className={styles.progress}>
      <div className={styles.line}>
        <span className={styles.position}>{position ? `Foto ${position} de ${total}` : `${total} fotos`}</span>
        <span className={styles.counts}>
          <strong className={styles.selected}>
            <span className={styles.dot} aria-hidden="true" />
            {selectedLabel}
          </strong>
          <span className={styles.remaining}>{remainingLabel}</span>
        </span>
      </div>

      <div
        className={styles.bar}
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={total}
        aria-valuenow={evaluated}
        aria-label={`${evaluated} de ${total} fotos avaliadas`}
      >
        <div className={styles.fill} style={{ width: `${percent}%` }} />
      </div>
    </div>
  );
}
