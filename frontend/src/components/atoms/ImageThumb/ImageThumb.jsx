import { useEffect, useRef, useState } from 'react';
import { fetchGalleryImageBlob } from '../../../api/galleries';
import { ImageIcon } from '../Icon';
import styles from './ImageThumb.module.css';

/**
 * Miniatura para imagens servidas pela rota autenticada interna
 * (`/galleries/{gallery}/images/{image}/file`). Como o disco de storage é
 * privado, um <img src> direto não funciona — buscamos como blob (o
 * apiClient já injeta o Bearer token) e criamos um object URL local.
 *
 * A busca só começa quando o elemento entra (ou está perto de entrar) na
 * viewport — numa galeria com 50 fotos, isso evita disparar 50 requisições
 * autenticadas de uma vez só quando a tela abre.
 */
export default function ImageThumb({ src, alt, className = '' }) {
  const [objectUrl, setObjectUrl] = useState(null);
  const [hasError, setHasError] = useState(false);
  const [isVisible, setIsVisible] = useState(false);
  const wrapperRef = useRef(null);

  useEffect(() => {
    const element = wrapperRef.current;
    if (!element || typeof IntersectionObserver === 'undefined') {
      setIsVisible(true);
      return undefined;
    }

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setIsVisible(true);
          observer.disconnect();
        }
      },
      { rootMargin: '200px' },
    );

    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    if (!isVisible) return undefined;

    let currentUrl = null;
    let cancelled = false;

    setHasError(false);
    setObjectUrl(null);

    fetchGalleryImageBlob(src)
      .then((url) => {
        if (cancelled) {
          URL.revokeObjectURL(url);
          return;
        }
        currentUrl = url;
        setObjectUrl(url);
      })
      .catch(() => !cancelled && setHasError(true));

    return () => {
      cancelled = true;
      if (currentUrl) URL.revokeObjectURL(currentUrl);
    };
  }, [src, isVisible]);

  return (
    <div ref={wrapperRef} className={[styles.wrapper, className].filter(Boolean).join(' ')}>
      {!objectUrl && !hasError && <div className={styles.skeleton} aria-hidden="true" />}
      {hasError && <ImageIcon width={22} height={22} className={styles.errorIcon} />}
      {objectUrl && <img src={objectUrl} alt={alt} className={styles.image} decoding="async" draggable="false" />}
    </div>
  );
}
