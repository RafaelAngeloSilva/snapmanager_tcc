import styles from './CircularLoader.module.css';

/**
 * Indicador de carregamento circular, centralizado, com texto opcional
 * abaixo. Usado nos carregamentos de galeria (lista, detalhes e página
 * pública do cliente).
 *
 * tone="onDark" para fundos sempre escuros.
 */
export default function CircularLoader({ label = 'Carregando…', size = 44, tone = 'default', className = '' }) {
  return (
    <div className={[styles.wrapper, tone === 'onDark' ? styles.onDark : '', className].filter(Boolean).join(' ')} role="status" aria-live="polite">
      <svg className={styles.svg} width={size} height={size} viewBox="0 0 50 50" aria-hidden="true">
        <circle className={styles.track} cx="25" cy="25" r="20" fill="none" strokeWidth="4" />
        <circle className={styles.arc} cx="25" cy="25" r="20" fill="none" strokeWidth="4" strokeLinecap="round" />
      </svg>
      {label ? <span className={styles.label}>{label}</span> : <span className={styles.srOnly}>Carregando</span>}
    </div>
  );
}
