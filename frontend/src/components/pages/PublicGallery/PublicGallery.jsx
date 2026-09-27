import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import Button from '../../atoms/Button';
import ThemeToggle from '../../atoms/ThemeToggle';
import { CheckCircleIcon } from '../../atoms/Icon';
import PublicGalleryGrid from '../../organisms/PublicGalleryGrid';
import SnapSwipeMode from '../../organisms/SnapSwipeMode';
import GalleryViewSwitch from '../../molecules/GalleryViewSwitch';
import GalleryLightbox from '../../organisms/GalleryLightbox';
import { fetchPublicGallery, selectPublicGalleryImages } from '../../../api/publicGallery';
import { formatDateTime } from '../../../utils/formatters';
import logo from '../../../assets/logo-lockup-light.png';
import styles from './PublicGallery.module.css';

// O modo escolhido vale só para esta aba/sessão — o link público continua
// exatamente o mesmo, e nada disso vai para o servidor.
const MODE_STORAGE_KEY = 'snapmanager_gallery_mode';

function storedMode(token) {
  try {
    return sessionStorage.getItem(`${MODE_STORAGE_KEY}:${token}`) || 'gallery';
  } catch {
    return 'gallery';
  }
}

const STATUS_MESSAGES = {
  expired: 'Esta galeria expirou e não está mais disponível para seleção de fotos.',
  closed: 'Esta galeria foi encerrada pelo estúdio e não está mais disponível para seleção de fotos.',
};

export default function PublicGallery() {
  const { token } = useParams();

  const [gallery, setGallery] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState('');

  const [selectedIds, setSelectedIds] = useState([]);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [lightboxIndex, setLightboxIndex] = useState(null);
  const [mode, setMode] = useState(() => storedMode(token));

  useEffect(() => {
    setIsLoading(true);
    setNotFound(false);
    setError('');

    fetchPublicGallery(token)
      .then((data) => {
        setGallery(data);
        setSelectedIds((data.images || []).filter((image) => image.is_selected).map((image) => image.id));
      })
      .catch((err) => {
        if (err.response?.status === 404) {
          setNotFound(true);
        } else {
          setError('Não foi possível carregar a galeria. Tente novamente em instantes.');
        }
      })
      .finally(() => setIsLoading(false));
  }, [token]);

  function handleModeChange(nextMode) {
    setMode(nextMode);

    try {
      sessionStorage.setItem(`${MODE_STORAGE_KEY}:${token}`, nextMode);
    } catch {
      // Navegador sem sessionStorage (aba privada em alguns casos): o modo
      // continua valendo nesta visita, só não é lembrado.
    }
  }

  const handleToggle = useCallback((imageId) => {
    setSelectedIds((prev) => (prev.includes(imageId) ? prev.filter((id) => id !== imageId) : [...prev, imageId]));
  }, []);

  const handleExpand = useCallback((index) => {
    setLightboxIndex(index);
  }, []);

  const handleCloseLightbox = useCallback(() => {
    setLightboxIndex(null);
  }, []);

  const handleNavigateLightbox = useCallback(
    (delta) => {
      const total = gallery?.images?.length || 0;

      if (total === 0) return;

      setLightboxIndex((current) => (current === null ? current : (current + delta + total) % total));
    },
    [gallery],
  );

  async function handleConfirm() {
    setIsSubmitting(true);
    setSubmitError('');

    try {
      const response = await selectPublicGalleryImages(token, selectedIds);
      setGallery(response.data);
      setConfirmed(true);
    } catch {
      setSubmitError('Não foi possível confirmar sua seleção. Tente novamente.');
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className={styles.page}>
      <header className={styles.hero}>
        <ThemeToggle variant="onDark" className={styles.themeToggle} />
        <div className={styles.heroGlowOne} />
        <div className={styles.heroGlowTwo} />
        <div className={styles.heroGrain} />

        <div className={styles.heroContent}>
          <img src={logo} alt="SnapManager" className={styles.brandLogo} />

          {gallery && (
            <>
              <h1 className={styles.title}>{gallery.title}</h1>
              {gallery.description && <p className={styles.description}>{gallery.description}</p>}
              {gallery.expires_at && (
                <p className={styles.expiresAt}>Disponível até {formatDateTime(gallery.expires_at)}</p>
              )}

              {gallery.images?.length > 0 && (
                <div className={styles.modeSwitch}>
                  <GalleryViewSwitch mode={mode} onChange={handleModeChange} />
                </div>
              )}
            </>
          )}
        </div>
      </header>

      <main className={styles.content}>
        {isLoading && (
          <div className={styles.loadingBox}>
            <Spinner /> Carregando galeria...
          </div>
        )}

        {!isLoading && notFound && (
          <EmptyState
            tone="error"
            title="Galeria não encontrada"
            message="Verifique se o link foi copiado corretamente ou entre em contato com o estúdio."
          />
        )}

        {!isLoading && !notFound && error && <EmptyState tone="error" title="Erro" message={error} />}

        {!isLoading && !notFound && !error && gallery && (
          <>
            {!gallery.is_accessible && (
              <div className={styles.noticeBox}>
                {STATUS_MESSAGES[gallery.status] || 'Esta galeria não está mais disponível para seleção de fotos.'}
              </div>
            )}

            {confirmed && (
              <div className={styles.successBox}>
                <CheckCircleIcon width={20} height={20} />
                Seleção enviada com sucesso! O estúdio já pode ver as fotos escolhidas.
              </div>
            )}

            {mode === 'gallery' ? (
              <PublicGalleryGrid
                images={gallery.images}
                selectedIds={selectedIds}
                onToggle={handleToggle}
                onExpand={handleExpand}
                disabled={!gallery.is_accessible}
              />
            ) : (
              <SnapSwipeMode
                images={gallery.images}
                selectedIds={selectedIds}
                onToggle={handleToggle}
                onExpand={handleExpand}
                onConfirm={handleConfirm}
                isConfirming={isSubmitting}
                confirmError={submitError}
                confirmed={confirmed}
                disabled={!gallery.is_accessible}
                isLightboxOpen={lightboxIndex !== null}
              />
            )}

            {mode === 'gallery' && gallery.is_accessible && gallery.images?.length > 0 && (
              <div className={styles.confirmBar}>
                <span className={styles.confirmCount}>
                  {selectedIds.length} foto(s) selecionada(s)
                </span>

                {submitError && <span className={styles.submitError}>{submitError}</span>}

                <Button onClick={handleConfirm} isLoading={isSubmitting}>
                  {isSubmitting ? <Spinner size="sm" /> : 'Confirmar seleção'}
                </Button>
              </div>
            )}
          </>
        )}
      </main>

      {lightboxIndex !== null && gallery?.images && (
        <GalleryLightbox
          images={gallery.images}
          index={lightboxIndex}
          selectedIds={selectedIds}
          onToggle={handleToggle}
          onClose={handleCloseLightbox}
          onNavigate={handleNavigateLightbox}
          disabled={!gallery.is_accessible}
        />
      )}
    </div>
  );
}
