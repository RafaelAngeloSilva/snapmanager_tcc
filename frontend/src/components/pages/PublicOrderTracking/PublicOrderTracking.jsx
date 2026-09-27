import { useEffect, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import ThemeToggle from '../../atoms/ThemeToggle';
import { CheckIcon } from '../../atoms/Icon';
import { fetchPublicOrderTracking } from '../../../api/orderTracking';
import { formatDate, formatDueDate } from '../../../utils/formatters';
import logo from '../../../assets/logo-lockup-light.png';
import styles from './PublicOrderTracking.module.css';

/**
 * "Acompanhe seu Pedido" — página pública do cliente (/acompanhar/:token).
 *
 * Sem login: o acesso é pelo token exclusivo do pedido. Mostra só o
 * andamento (etapas, etapa atual, prazo). Com ?preview=1 (botão
 * "Visualizar como cliente" da equipe) a visita não é contabilizada.
 */
export default function PublicOrderTracking() {
  const { token } = useParams();
  const [searchParams] = useSearchParams();
  const isPreview = searchParams.get('preview') === '1';

  const [order, setOrder] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    setIsLoading(true);
    setNotFound(false);
    setError('');

    fetchPublicOrderTracking(token, { preview: isPreview })
      .then(setOrder)
      .catch((err) => {
        if (err.response?.status === 404) {
          setNotFound(true);
        } else {
          setError('Não foi possível carregar o acompanhamento. Tente novamente em instantes.');
        }
      })
      .finally(() => setIsLoading(false));
  }, [token, isPreview]);

  useEffect(() => {
    document.title = order ? `Acompanhe seu pedido — ${order.title}` : 'Acompanhe seu pedido';
  }, [order]);

  return (
    <div className={styles.page}>
      <header className={styles.hero}>
        <ThemeToggle variant="onDark" className={styles.themeToggle} />

        <div className={styles.heroContent}>
          <img src={logo} alt="SnapManager" className={styles.brandLogo} />
          <p className={styles.eyebrow}>Acompanhe seu pedido</p>

          {order && (
            <>
              <h1 className={styles.title}>{order.title}</h1>
              {order.client_first_name && <p className={styles.greeting}>Olá, {order.client_first_name}!</p>}
            </>
          )}
        </div>
      </header>

      <main className={styles.content}>
        {isPreview && (
          <div className={styles.previewNotice}>
            Pré-visualização da equipe — esta visita não é contada como acesso do cliente.
          </div>
        )}

        {isLoading && (
          <div className={styles.loadingBox}>
            <Spinner /> Carregando...
          </div>
        )}

        {!isLoading && notFound && (
          <EmptyState
            tone="error"
            title="Link indisponível"
            message="Este link de acompanhamento não existe ou foi desativado pelo estúdio. Peça um novo link ao estúdio."
          />
        )}

        {!isLoading && !notFound && error && <EmptyState tone="error" title="Erro" message={error} />}

        {!isLoading && !notFound && !error && order && (
          <>
            <section className={`${styles.statusCard} ${order.is_cancelled ? styles.cancelled : ''}`}>
              <span className={styles.statusLabel}>Etapa atual</span>
              <p className={styles.statusValue}>{order.status_label}</p>
              {order.status_message && <p className={styles.statusMessage}>{order.status_message}</p>}

              <dl className={styles.facts}>
                {order.service_type && (
                  <div>
                    <dt>Serviço</dt>
                    <dd>{order.service_type}</dd>
                  </div>
                )}
                {order.due_date && !order.is_cancelled && (
                  <div>
                    <dt>Previsão de entrega</dt>
                    <dd>{formatDueDate(order.due_date)}</dd>
                  </div>
                )}
                <div>
                  <dt>Pedido feito em</dt>
                  <dd>{formatDate(order.created_at)}</dd>
                </div>
              </dl>
            </section>

            {!order.is_cancelled && (
              <section className={styles.timelineCard} aria-label="Etapas do pedido">
                <h2 className={styles.timelineTitle}>Etapas</h2>
                <ol className={styles.timeline}>
                  {order.steps.map((step) => (
                    <li key={step.status} className={`${styles.step} ${styles[step.state]}`}>
                      <span className={styles.marker} aria-hidden="true">
                        {step.state === 'done' && <CheckIcon width={14} height={14} />}
                      </span>
                      <div className={styles.stepBody}>
                        <span className={styles.stepLabel}>
                          {step.label}
                          {step.state === 'current' && <span className={styles.nowTag}>agora</span>}
                        </span>
                        {step.reached_at && <span className={styles.stepDate}>{formatDate(step.reached_at)}</span>}
                      </div>
                    </li>
                  ))}
                </ol>
              </section>
            )}

            <p className={styles.footer}>
              Última atualização em {formatDate(order.updated_at)}. Dúvidas? Fale com o estúdio pelo mesmo canal em
              que recebeu este link.
            </p>
          </>
        )}
      </main>
    </div>
  );
}
