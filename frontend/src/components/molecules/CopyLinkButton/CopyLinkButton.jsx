import { useState } from 'react';
import Button from '../../atoms/Button';
import { CopyIcon, CheckCircleIcon } from '../../atoms/Icon';
import styles from './CopyLinkButton.module.css';

export default function CopyLinkButton({ value, label = 'Copiar link público' }) {
  const [copied, setCopied] = useState(false);

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(value);
    } catch {
      // Fallback para navegadores/contextos sem Clipboard API (ex.: http
      // não-seguro). Seleciona um input temporário e usa execCommand.
      const input = document.createElement('input');
      input.value = value;
      document.body.appendChild(input);
      input.select();
      document.execCommand('copy');
      document.body.removeChild(input);
    }

    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  return (
    <Button type="button" variant="secondary" onClick={handleCopy} className={styles.button}>
      {copied ? <CheckCircleIcon /> : <CopyIcon />}
      {copied ? 'Link copiado!' : label}
    </Button>
  );
}
