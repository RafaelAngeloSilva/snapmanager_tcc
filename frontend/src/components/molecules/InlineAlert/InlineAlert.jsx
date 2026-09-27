import styles from './InlineAlert.module.css';

/**
 * Aviso curto no topo de uma listagem — confirmação de uma ação ou erro
 * recuperável. Não substitui o EmptyState (que ocupa a área toda quando não
 * há o que mostrar): aqui o conteúdo da página continua visível abaixo.
 */
export default function InlineAlert({ tone = 'info', children, action, onDismiss }) {
  return (
    <div className={`${styles.alert} ${styles[tone] || styles.info}`} role="status">
      <span className={styles.message}>{children}</span>

      <span className={styles.trailing}>
        {action}
        {onDismiss && (
          <button type="button" className={styles.dismiss} onClick={onDismiss} aria-label="Fechar aviso">
            ✕
          </button>
        )}
      </span>
    </div>
  );
}
