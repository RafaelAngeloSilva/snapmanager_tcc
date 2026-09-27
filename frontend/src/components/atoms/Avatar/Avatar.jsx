import styles from './Avatar.module.css';

function getInitials(name) {
  if (!name) return 'U';

  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('');
}

/**
 * Foto de perfil circular quando existe; iniciais do nome como fallback.
 * "onDark" é pra usar sobre fundos sempre escuros (Sidebar) — cores fixas,
 * não reativas ao tema, mesma convenção do ThemeToggle/CopyLinkButton.
 */
export default function Avatar({ name, photoUrl, size = 'md', variant = 'default', className = '' }) {
  const classes = [
    styles.avatar,
    styles[size] || styles.md,
    variant === 'onDark' ? styles.onDark : styles.default,
    className,
  ]
    .filter(Boolean)
    .join(' ');

  if (photoUrl) {
    return <img src={photoUrl} alt="" className={classes} />;
  }

  return <span className={classes}>{getInitials(name)}</span>;
}
