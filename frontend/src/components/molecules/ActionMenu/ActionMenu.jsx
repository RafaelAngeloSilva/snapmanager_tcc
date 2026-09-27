import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Spinner from '../../atoms/Spinner';
import { MoreIcon } from '../../atoms/Icon';
import styles from './ActionMenu.module.css';

const MENU_WIDTH = 220;
const GAP = 6;

/**
 * Menu de "mais ações" (três pontos). Mesmo padrão do UserMenu (fecha com
 * clique fora e Esc), com duas diferenças:
 *  - o menu vai por portal para o <body> com posição fixa: dentro de uma
 *    tabela (Card com overflow: hidden) ele seria cortado nas últimas
 *    linhas; perto do rodapé da tela, abre para cima;
 *  - teclado: setas navegam entre os itens, Esc devolve o foco ao botão.
 *
 * Cada item tem ícone + rótulo (nunca só cor/ícone).
 *
 * @param {{ label: string, items: Array<{ key: string, label: string, icon?: JSX.Element, onSelect: () => void, disabled?: boolean, loading?: boolean }>, disabled?: boolean }} props
 */
export default function ActionMenu({ label, items, disabled = false }) {
  const [isOpen, setIsOpen] = useState(false);
  const [position, setPosition] = useState(null);
  const triggerRef = useRef(null);
  const menuRef = useRef(null);

  function close({ restoreFocus = false } = {}) {
    setIsOpen(false);
    if (restoreFocus) triggerRef.current?.focus();
  }

  // Calcula a posição antes de pintar, para o menu não "pular".
  useLayoutEffect(() => {
    if (!isOpen || !triggerRef.current) return;

    const rect = triggerRef.current.getBoundingClientRect();
    const menuHeight = menuRef.current?.offsetHeight ?? 0;
    const openUp = rect.bottom + GAP + menuHeight > window.innerHeight && rect.top - GAP - menuHeight > 0;
    const left = Math.min(Math.max(8, rect.right - MENU_WIDTH), window.innerWidth - MENU_WIDTH - 8);

    setPosition({ top: openUp ? rect.top - GAP - menuHeight : rect.bottom + GAP, left });
  }, [isOpen, items.length]);

  useEffect(() => {
    if (!isOpen) return undefined;

    menuRef.current?.querySelector('[role="menuitem"]:not(:disabled)')?.focus();

    function handlePointer(event) {
      if (!menuRef.current?.contains(event.target) && !triggerRef.current?.contains(event.target)) close();
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        // Não deixa o Esc chegar ao modal por trás.
        event.stopPropagation();
        close({ restoreFocus: true });
      }
    }

    // Rolar ou redimensionar deixaria o menu solto na tela: fecha.
    function handleViewportChange() {
      close();
    }

    document.addEventListener('mousedown', handlePointer);
    document.addEventListener('keydown', handleKeyDown, true);
    window.addEventListener('resize', handleViewportChange);
    window.addEventListener('scroll', handleViewportChange, true);

    return () => {
      document.removeEventListener('mousedown', handlePointer);
      document.removeEventListener('keydown', handleKeyDown, true);
      window.removeEventListener('resize', handleViewportChange);
      window.removeEventListener('scroll', handleViewportChange, true);
    };
  }, [isOpen]);

  function handleMenuKeyDown(event) {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;

    event.preventDefault();
    const enabled = [...menuRef.current.querySelectorAll('[role="menuitem"]:not(:disabled)')];
    const index = enabled.indexOf(document.activeElement);
    const next = event.key === 'ArrowDown' ? (index + 1) % enabled.length : (index - 1 + enabled.length) % enabled.length;
    enabled[next]?.focus();
  }

  function handleSelect(item) {
    close({ restoreFocus: true });
    item.onSelect();
  }

  if (items.length === 0) return null;

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        className={styles.trigger}
        onClick={() => setIsOpen((current) => !current)}
        aria-haspopup="menu"
        aria-expanded={isOpen}
        aria-label={label}
        title={label}
        disabled={disabled}
      >
        <MoreIcon width={16} height={16} />
      </button>

      {isOpen &&
        createPortal(
          <div
            ref={menuRef}
            className={styles.menu}
            role="menu"
            aria-label={label}
            style={{ top: position?.top ?? -9999, left: position?.left ?? -9999, width: MENU_WIDTH }}
            onKeyDown={handleMenuKeyDown}
          >
            {items.map((item) => (
              <button
                key={item.key}
                type="button"
                role="menuitem"
                className={styles.item}
                onClick={() => handleSelect(item)}
                disabled={item.disabled || item.loading}
              >
                <span className={styles.icon} aria-hidden="true">
                  {item.loading ? <Spinner size="sm" /> : item.icon}
                </span>
                {item.label}
              </button>
            ))}
          </div>,
          document.body,
        )}
    </>
  );
}
