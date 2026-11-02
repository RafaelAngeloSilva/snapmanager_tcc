import { useEffect, useRef, useState } from 'react';

/** O arrasto traz arquivos do computador (e não texto/links/elementos da página)? */
export function draggingFiles(event) {
  return Array.from(event.dataTransfer?.types || []).includes('Files');
}

/**
 * Enquanto `active`, um arquivo solto fora de uma área de upload não faz o
 * navegador abrir a foto (e sair do SnapManager).
 */
export function usePreventFileDropNavigation(active) {
  useEffect(() => {
    if (!active) return undefined;

    function prevent(event) {
      if (draggingFiles(event)) event.preventDefault();
    }

    window.addEventListener('dragover', prevent);
    window.addEventListener('drop', prevent);

    return () => {
      window.removeEventListener('dragover', prevent);
      window.removeEventListener('drop', prevent);
    };
  }, [active]);
}

/**
 * Área que aceita arquivos arrastados do computador.
 * Devolve `isDragging` (para o destaque visual) e os handlers para espalhar
 * no elemento. Um contador de dragenter/dragleave evita o "pisca" quando o
 * cursor passa sobre os filhos da área.
 */
export function useFileDrop(onFiles, { disabled = false } = {}) {
  const [isDragging, setIsDragging] = useState(false);
  const depth = useRef(0);

  useEffect(() => {
    if (disabled) {
      depth.current = 0;
      setIsDragging(false);
    }
  }, [disabled]);

  const handlers = {
    onDragEnter(event) {
      if (!draggingFiles(event) || disabled) return;
      event.preventDefault();
      depth.current += 1;
      setIsDragging(true);
    },
    onDragOver(event) {
      if (!draggingFiles(event)) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = disabled ? 'none' : 'copy';
    },
    onDragLeave(event) {
      if (!draggingFiles(event)) return;
      depth.current = Math.max(0, depth.current - 1);
      if (depth.current === 0) setIsDragging(false);
    },
    onDrop(event) {
      if (!draggingFiles(event)) return;
      event.preventDefault();
      depth.current = 0;
      setIsDragging(false);
      if (!disabled && event.dataTransfer.files?.length) onFiles(event.dataTransfer.files);
    },
  };

  return { isDragging, handlers };
}
