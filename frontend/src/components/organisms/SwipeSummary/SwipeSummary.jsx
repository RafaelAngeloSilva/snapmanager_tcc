import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { CheckCircleIcon } from '../../atoms/Icon';
import styles from './SwipeSummary.module.css';

/**
 * Tela de fim da sequência. Chegar ao último cartão NÃO finaliza a
 * seleção: a finalização continua sendo uma confirmação explícita, no mesmo
 * fluxo da galeria tradicional.
 */
export default function SwipeSummary({
  total,
  selectedCount,
  onReviewSelected,
  onReviewAll,
  onFinish,
  isFinishing,
  error,
  disabled,
  confirmed,
}) {
  return (
    <div className={styles.summary}>
      <span className={styles.icon} aria-hidden="true">
        <CheckCircleIcon width={30} height={30} />
      </span>

      <h2 className={styles.title}>Sua seleção está pronta!</h2>

      <p className={styles.counts}>
        <strong>{total}</strong> fotos avaliadas
        <span aria-hidden="true"> · </span>
        <strong className={styles.selected}>{selectedCount}</strong> fotos selecionadas
      </p>

      {selectedCount === 0 && (
        <p className={styles.warning}>Você ainda não escolheu nenhuma foto. Reveja as fotos antes de finalizar.</p>
      )}

      {confirmed && (
        <p className={styles.confirmed}>Seleção enviada ao estúdio. Você ainda pode alterar e confirmar de novo enquanto a galeria estiver disponível.</p>
      )}

      {error && (
        <p className={styles.error} role="alert">
          {error}
        </p>
      )}

      <div className={styles.actions}>
        <Button variant="secondary" onClick={onReviewSelected} disabled={isFinishing}>
          Revisar selecionadas
        </Button>
        <Button variant="secondary" onClick={onReviewAll} disabled={isFinishing}>
          Rever todas as fotos
        </Button>
        <Button onClick={onFinish} isLoading={isFinishing} disabled={disabled || isFinishing}>
          {isFinishing ? <Spinner size="sm" /> : 'Finalizar seleção'}
        </Button>
      </div>
    </div>
  );
}
