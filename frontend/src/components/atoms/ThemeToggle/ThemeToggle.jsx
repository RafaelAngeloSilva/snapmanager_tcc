import { useTheme } from '../../../context/ThemeContext';
import { MoonIcon, SunIcon } from '../Icon';
import styles from './ThemeToggle.module.css';

export default function ThemeToggle({ className = '', variant = 'default' }) {
  const { isDark, toggleTheme } = useTheme();

  return (
    <button
      type="button"
      className={[styles.toggle, variant === 'onDark' ? styles.onDark : '', className].filter(Boolean).join(' ')}
      onClick={toggleTheme}
      role="switch"
      aria-checked={isDark}
      aria-label={isDark ? 'Mudar para tema claro' : 'Mudar para tema escuro'}
      title={isDark ? 'Tema escuro' : 'Tema claro'}
    >
      {isDark ? <MoonIcon width={17} height={17} /> : <SunIcon width={17} height={17} />}
    </button>
  );
}
