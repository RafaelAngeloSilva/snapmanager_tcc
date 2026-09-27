import { useEffect, useRef } from 'react';
import logo from '../../../assets/logo-lockup-light.png';
import heroVideo from '../../../assets/login-hero.mp4';
import heroPoster from '../../../assets/login-hero-poster.jpg';
import styles from './AuthTemplate.module.css';

const HIGHLIGHTS = [
  'Agenda sem conflito de horário entre profissionais',
  'Pedidos com histórico de produção auditável',
  'Painel com alertas operacionais em tempo real',
];

export default function AuthTemplate({ children }) {
  const videoRef = useRef(null);

  // Respeita "reduzir movimento" do sistema: pausa no primeiro quadro em vez
  // de tocar em loop indefinidamente.
  useEffect(() => {
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReducedMotion && videoRef.current) {
      videoRef.current.pause();
    }
  }, []);

  return (
    <div className={styles.page}>
      <section className={styles.hero} aria-hidden="true">
        <video
          ref={videoRef}
          className={styles.heroVideo}
          src={heroVideo}
          poster={heroPoster}
          autoPlay
          loop
          muted
          playsInline
          preload="auto"
          disablePictureInPicture
        />
        <div className={styles.heroScrim} />

        <div className={styles.viewfinder}>
          <span className={`${styles.corner} ${styles.cornerTl}`} />
          <span className={`${styles.corner} ${styles.cornerTr}`} />
          <span className={`${styles.corner} ${styles.cornerBl}`} />
          <span className={`${styles.corner} ${styles.cornerBr}`} />
        </div>

        <div className={styles.heroContent}>
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
