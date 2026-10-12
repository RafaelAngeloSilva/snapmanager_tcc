import { useId, useState } from 'react';
import Input from '../Input';
import { EyeIcon, EyeOffIcon } from '../Icon';
import styles from './PasswordInput.module.css';

/**
 * Campo de senha com botão de mostrar/ocultar.
 *
 * - Começa SEMPRE oculto; o estado vive só na memória do componente (nada
 *   em localStorage/sessionStorage) e volta a "oculto" quando o campo some.
 * - O botão é um <button type="button"> de verdade: funciona com Tab +
 *   Enter/Espaço, tem aria-label que diz a ação ("Mostrar senha" /
 *   "Ocultar senha") e não envia o formulário.
 * - O foco permanece no campo ao clicar com o mouse (o cursor não pula), e
 *   o ícone ocupa um espaço reservado dentro do input — não muda o tamanho
 *   do campo.
 *
 * Aceita as mesmas props do Input (id, value, onChange, invalid, ...), então
 * funciona dentro do FormField: <FormField as={PasswordInput} ... />.
 */
export default function PasswordInput({ id, className = '', disabled, ...rest }) {
  const [visible, setVisible] = useState(false);
  const fallbackId = useId();
  const inputId = id || fallbackId;

  // Mouse/toque: o onMouseDown do botão impede que o campo perca o foco
  // (o cursor continua onde estava). Teclado: o foco fica no próprio botão,
  // como esperado por quem navega com Tab.
  function toggle() {
    setVisible((current) => !current);
  }

  return (
    <span className={styles.wrapper}>
      <Input
        {...rest}
        id={inputId}
        type={visible ? 'text' : 'password'}
        disabled={disabled}
        className={[styles.input, className].filter(Boolean).join(' ')}
        // Texto visível não deve ser corrigido/capitalizado pelo teclado do celular.
        autoCapitalize="none"
        autoCorrect="off"
        spellCheck={false}
      />
      <button
        type="button"
        className={styles.toggle}
        onClick={toggle}
        // Evita que o clique tire o foco do campo antes do toggle.
        onMouseDown={(event) => event.preventDefault()}
        aria-label={visible ? 'Ocultar senha' : 'Mostrar senha'}
        aria-controls={inputId}
        title={visible ? 'Ocultar senha' : 'Mostrar senha'}
        disabled={disabled}
      >
        {visible ? <EyeOffIcon width={18} height={18} aria-hidden="true" /> : <EyeIcon width={18} height={18} aria-hidden="true" />}
      </button>
    </span>
  );
}
