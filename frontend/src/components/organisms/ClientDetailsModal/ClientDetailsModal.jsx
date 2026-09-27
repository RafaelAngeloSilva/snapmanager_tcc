import { useEffect, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import { fetchClient } from '../../../api/clients';
import { formatLongDate } from '../../../utils/formatters';
import { formatDocument } from '../../../utils/cpf';
import { formatFullAddress } from '../../../utils/address';
import styles from './ClientDetailsModal.module.css';

export default function ClientDetailsModal({ isOpen, clientId, onClose, onEdit }) {
  const [client, setClient] = useState(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen || !clientId) return;

    setIsLoading(true);
    setError('');
    setClient(null);

    fetchClient(clientId)
      .then(setClient)
      .catch(() => setError('Não foi possível carregar os detalhes do cliente.'))
      .finally(() => setIsLoading(false));
  }, [isOpen, clientId]);

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Detalhes do cliente"
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Fechar
          </Button>
          {client && <Button onClick={() => onEdit(client)}>Editar</Button>}
        </>
      }
    >
      {isLoading && (
        <div className={styles.loadingBox}>
          <Spinner /> Carregando...
        </div>
      )}

      {!isLoading && error && <EmptyState tone="error" title="Erro" message={error} />}

      {!isLoading && !error && client && (
        <>
          <h2 className={styles.title}>{client.name}</h2>

          <dl className={styles.details}>
            <div>
              <dt>E-mail</dt>
              <dd>{client.email || '—'}</dd>
            </div>
            <div>
              <dt>Telefone</dt>
              <dd>{client.phone || '—'}</dd>
            </div>
            <div>
              <dt>Documento</dt>
              <dd>{client.document ? formatDocument(client.document) : '—'}</dd>
            </div>
            <div>
              <dt>Cadastrado em</dt>
              <dd>{formatLongDate(client.created_at)}</dd>
            </div>
            <div className={styles.fullWidth}>
              <dt>Endereço</dt>
              <dd>{formatFullAddress(client) || '—'}</dd>
            </div>
            <div className={styles.fullWidth}>
              <dt>Observações</dt>
              <dd>{client.notes || '—'}</dd>
            </div>
          </dl>
        </>
      )}
    </Modal>
  );
}
