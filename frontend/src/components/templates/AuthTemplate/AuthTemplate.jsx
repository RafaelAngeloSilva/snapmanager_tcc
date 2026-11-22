import { useEffect, useRef, useState } from 'react';
import logo from '../../../assets/logo-lockup-light.png';
import heroVideo from '../../../assets/login-hero.mp4';
import heroPoster from '../../../assets/login-hero-poster.jpg';
import styles from './AuthTemplate.module.css';

const HIGHLIGHTS = [
  'Agenda sem conflito de horário entre profissionais',
  'Pedidos com histórico de produção auditável',
  'Painel com alertas operacionais em tempo real',
];

// Preferência "pausar o vídeo de fundo" (só deste navegador).
const PAUSE_STORAGE_KEY = 'snapmanager_login_video_paused';

function readPausedPreference() {
  try {
    return window.localStorage.getItem(PAUSE_STORAGE_KEY) === '1';
  } catch {
    return false;
  }
}

function savePausedPreference(paused) {
  try {
    if (paused) window.localStorage.setItem(PAUSE_STORAGE_KEY, '1');
    else window.localStorage.removeItem(PAUSE_STORAGE_KEY);
  } catch {
    // navegação privada / armazenamento bloqueado: só não lembra a escolha
  }
}

export default function AuthTemplate({ children }) {
  const videoRef = useRef(null);
  const [isPaused, setIsPaused] = useState(readPausedPreference);
  const pausedRef = useRef(isPaused);

  // O vídeo é uma sequência de fotos com transição suave. Antes, com
  // "reduzir movimento" ligado no sistema (no Windows: Efeitos de animação
  // desligados), ele ficava parado na capa — e parecia quebrado. Agora ele
  // toca sempre, e quem preferir pode pausá-lo no botão (a escolha fica
  // salva). Se o navegador recusar o autoplay, tenta de novo na primeira
  // interação com a página.
  useEffect(() => {
    const video = videoRef.current;
    if (!video) return undefined;

    // O autoplay só é liberado para vídeo mudo: garante a propriedade (o
    // React não grava o atributo "muted" no HTML).
    video.muted = true;
    video.defaultMuted = true;

    let waitingForInteraction = false;

    function retryOnInteraction() {
      window.removeEventListener('pointerdown', retryOnInteraction);
      window.removeEventListener('keydown', retryOnInteraction);
      waitingForInteraction = false;
      tryPlay();
    }

    function tryPlay() {
      if (pausedRef.current || !video) return;

      const attempt = video.play();
      attempt?.catch(() => {
        if (waitingForInteraction) return;
        waitingForInteraction = true;
        window.addEventListener('pointerdown', retryOnInteraction, { once: true });
        window.addEventListener('keydown', retryOnInteraction, { once: true });
      });
    }

    // Ao voltar para a aba, retoma (alguns navegadores pausam em segundo plano).
    function handleVisibility() {
      if (document.visibilityState === 'visible') tryPlay();
    }

    if (pausedRef.current) {
      video.pause();
    } else if (video.readyState >= 2) {
      tryPlay();
    } else {
      video.addEventListener('canplay', tryPlay, { once: true });
    }

    document.addEventListener('visibilitychange', handleVisibility);

    return () => {
      video.removeEventListener('canplay', tryPlay);
      document.removeEventListener('visibilitychange', handleVisibility);
      window.removeEventListener('pointerdown', retryOnInteraction);
      window.removeEventListener('keydown', retryOnInteraction);
    };
  }, []);

  function togglePlayback() {
    const video = videoRef.current;
    const nextPaused = !isPaused;

    pausedRef.current = nextPaused;
    setIsPaused(nextPaused);
    savePausedPreference(nextPaused);

    if (!video) return;
    if (nextPaused) video.pause();
    else video.play()?.catch(() => {});
  }

  return (
    <div className={styles.page}>
      <section className={styles.hero}>
        <video
          ref={videoRef}
          className={styles.heroVideo}
          src={heroVideo}
          poster={heroPoster}
          autoPlay={!isPaused}
          loop
          muted
          playsInline
          preload="auto"
          disablePictureInPicture
          aria-hidden="true"
          tabIndex={-1}
          data-testid="login-hero-video"
        />
        <div className={styles.heroScrim} aria-hidden="true" />

        <div className={styles.viewfinder} aria-hidden="true">
          <span className={`${styles.corner} ${styles.cornerTl}`} />
          <span className={`${styles.corner} ${styles.cornerTr}`} />
          <span className={`${styles.corner} ${styles.cornerBl}`} />
          <span className={`${styles.corner} ${styles.cornerBr}`} />
        </div>

        <div className={styles.heroContent} aria-hidden="true">
          <div className={styles.heroBrand}>
            <img src={logo} alt="SnapManager" className={styles.heroLogo} />
          </div>

          <p className={styles.heroTagline}>
            Cada clique organizado. <br />
            Cada entrega no tempo certo.
          </p>

          <p className={styles.heroSubtext}>
            Gestão de clientes, agenda e produção para estúdios fotográficos que
            levam a experiência do cliente tão a sério quanto o enquadramento.
          </p>

          <ul className={styles.heroList}>
            {HIGHLIGHTS.map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
        </div>

        <button
          type="button"
          className={styles.videoToggle}
          onClick={togglePlayback}
          aria-label={isPaused ? 'Reproduzir vídeo de fundo' : 'Pausar vídeo de fundo'}
          title={isPaused ? 'Reproduzir vídeo de fundo' : 'Pausar vídeo de fundo'}
        >
          {isPaused ? (
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5Z" />
            </svg>
          ) : (
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <rect x="6" y="5" width="4" height="14" rx="1.2" />
              <rect x="14" y="5" width="4" height="14" rx="1.2" />
            </svg>
          )}
        </button>
      </section>

      <section className={styles.formSide}>
        <div className={styles.formGrid} aria-hidden="true" />
        <div className={`${styles.lensRing} ${styles.lensRingTop}`} aria-hidden="true" />
        <div className={`${styles.lensRing} ${styles.lensRingBottom}`} aria-hidden="true" />

        <div className={styles.formWrap}>{children}</div>
        <p className={styles.footer}>SnapManager — gestão para estúdios fotográficos</p>
      </section>
    </div>
  );
}
