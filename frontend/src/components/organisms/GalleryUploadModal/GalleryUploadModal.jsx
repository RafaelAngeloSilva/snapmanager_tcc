import { useEffect, useRef, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { UploadIcon, TrashIcon } from '../../atoms/Icon';
import { uploadGalleryImages } from '../../../api/galleries';
import styles from './GalleryUploadModal.module.css';

const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_SIZE_BYTES = 10 * 1024 * 1024;

/**
 * Mensagem amigável para a falha de UMA foto. Nunca mostra detalhes
 * técnicos (bucket, AWS, caminhos) — esses ficam só no log do servidor.
 */
function describeUploadError(err) {
  const status = err.response?.status;

  if (status === 422) {
    return Object.values(err.response.data.errors || {}).flat().join(' ') || 'arquivo recusado pela validação.';
  }

  if (status === 413) return 'arquivo grande demais para o servidor.';
  if (!err.response) return 'sem resposta do servidor.';

  return 'erro no servidor.';
}

function formatSize(bytes) {
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function GalleryUploadModal({ isOpen, gallery, onClose, onUploaded }) {
  const [pending, setPending] = useState([]);
  const [previews, setPreviews] = useState({});
  const [isUploading, setIsUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  // Foto sendo enviada agora (1..total), para o texto "Enviando foto X de N".
  const [current, setCurrent] = useState({ index: 0, total: 0 });
  const [error, setError] = useState('');
  const [localWarnings, setLocalWarnings] = useState([]);
  const inputRef = useRef(null);

  useEffect(() => {
    if (!isOpen) {
      setPending([]);
      setError('');
      setLocalWarnings([]);
      setProgress(0);
    }
  }, [isOpen]);

  useEffect(() => {
    const nextPreviews = {};
    pending.forEach((file, index) => {
      nextPreviews[index] = URL.createObjectURL(file);
    });
    setPreviews(nextPreviews);

    return () => {
      Object.values(nextPreviews).forEach((url) => URL.revokeObjectURL(url));
    };
  }, [pending]);

  function handleFilesSelected(event) {
    const files = Array.from(event.target.files || []);
    event.target.value = '';

    const accepted = [];
    const warnings = [];

    files.forEach((file) => {
      if (!ACCEPTED_TYPES.includes(file.type)) {
        warnings.push(`${file.name}: formato não suportado (use jpg, png ou webp).`);
      } else if (file.size > MAX_SIZE_BYTES) {
        warnings.push(`${file.name}: maior que 10MB.`);
      } else {
        accepted.push(file);
      }
    });

    setLocalWarnings(warnings);
    setPending((prev) => [...prev, ...accepted]);
  }

  function handleRemove(index) {
    setPending((prev) => prev.filter((_, i) => i !== index));
  }

  /**
   * Envia UMA foto por requisição, em sequência.
   *
   * Antes o lote inteiro ia numa requisição só: com o armazenamento no S3,
   * o servidor processava e enviava todas as fotos (original + 3 versões
   * cada) dentro do mesmo limite de tempo do PHP e estourava no meio do
   * lote. Uma por vez, cada requisição é curta, o progresso é real e uma
   * falha não derruba as outras: as fotos enviadas ficam salvas e só as que
   * falharam continuam na lista, prontas para reenviar.
   */
  async function handleUpload() {
    if (pending.length === 0 || isUploading) return;

    const queue = [...pending];
    const failed = [];
    const reasons = [];
    let uploadedCount = 0;

    setIsUploading(true);
    setError('');
    setProgress(0);

    try {
      for (let index = 0; index < queue.length; index += 1) {
        const file = queue[index];
        setCurrent({ index: index + 1, total: queue.length });

        try {
          await uploadGalleryImages(gallery.id, [file], (event) => {
            // O envio do arquivo é ~90% da etapa; o resto é o processamento no servidor.
            const sent = event.total ? event.loaded / event.total : 0;
            setProgress(Math.round(((index + sent * 0.9) / queue.length) * 100));
          });
          uploadedCount += 1;
        } catch (err) {
          failed.push(file);
          reasons.push(`${file.name}: ${describeUploadError(err)}`);
        }

        setProgress(Math.round(((index + 1) / queue.length) * 100));
      }
    } finally {
      setIsUploading(false);
      setCurrent({ index: 0, total: 0 });
    }

    if (failed.length === 0) {
      setPending([]);
      onUploaded();

      return;
    }

    setPending(failed);

    if (uploadedCount > 0) {
      // Atualiza a galeria por trás sem fechar o modal (as que falharam continuam aqui).
      onUploaded({ keepOpen: true });
      setError(
        `${uploadedCount} de ${queue.length} foto(s) enviadas. ${failed.length} não puderam ser enviadas e continuam abaixo para tentar de novo — ${reasons.join(' ')}`,
      );
    } else {
      setError(`Não foi possível enviar as fotos. Tente novamente. ${reasons.join(' ')}`.trim());
    }
  }

  if (!gallery) return null;

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={`Enviar fotos — ${gallery.title}`}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isUploading}>
            Fechar
          </Button>
          <Button onClick={handleUpload} isLoading={isUploading} disabled={pending.length === 0}>
            {isUploading ? <Spinner size="sm" /> : `Enviar ${pending.length || ''} foto(s)`.trim()}
          </Button>
        </>
      }
    >
      <div className={styles.body}>
        <input
          ref={inputRef}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          multiple
          onChange={handleFilesSelected}
          className={styles.hiddenInput}
          id="gallery-upload-input"
        />

        <label htmlFor="gallery-upload-input" className={styles.dropzone}>
          <UploadIcon width={24} height={24} />
          <span>Clique para selecionar fotos (jpg, png ou webp — até 10MB cada)</span>
        </label>

        {localWarnings.length > 0 && (
          <ul className={styles.warnings}>
            {localWarnings.map((warning) => (
              <li key={warning}>{warning}</li>
            ))}
          </ul>
        )}

        {error && <div className={styles.error}>{error}</div>}

        {isUploading && (
          <>
            <div className={styles.progressBar}>
              <div className={styles.progressFill} style={{ width: `${progress}%` }} />
            </div>
            {current.total > 0 && (
              <p className={styles.progressText} role="status">
                Enviando foto {current.index} de {current.total}…
              </p>
            )}
          </>
        )}

        {pending.length > 0 && (
          <div className={styles.grid}>
            {pending.map((file, index) => (
              <div key={`${file.name}-${index}`} className={styles.thumb}>
                <img src={previews[index]} alt={file.name} />
                <button
                  type="button"
                  className={styles.removeButton}
                  onClick={() => handleRemove(index)}
                  aria-label={`Remover ${file.name}`}
                  disabled={isUploading}
                >
                  <TrashIcon width={14} height={14} />
                </button>
                <span className={styles.thumbMeta}>{formatSize(file.size)}</span>
              </div>
            ))}
          </div>
        )}
      </div>
    </Modal>
  );
}
