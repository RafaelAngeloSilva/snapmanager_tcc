import { useEffect, useRef, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import { UploadIcon, TrashIcon } from '../../atoms/Icon';
import { uploadGalleryImages } from '../../../api/galleries';
import styles from './GalleryUploadModal.module.css';

const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_SIZE_BYTES = 10 * 1024 * 1024;

function formatSize(bytes) {
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function GalleryUploadModal({ isOpen, gallery, onClose, onUploaded }) {
  const [pending, setPending] = useState([]);
  const [previews, setPreviews] = useState({});
  const [isUploading, setIsUploading] = useState(false);
  const [progress, setProgress] = useState(0);
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

  async function handleUpload() {
    if (pending.length === 0) return;

    setIsUploading(true);
    setError('');
    setProgress(0);

    try {
      const uploaded = await uploadGalleryImages(gallery.id, pending, (event) => {
        if (event.total) setProgress(Math.round((event.loaded / event.total) * 100));
      });

      onUploaded(uploaded);
      setPending([]);
    } catch (err) {
      const status = err.response?.status;

      if (status === 422) {
        const messages = Object.values(err.response.data.errors || {})
          .flat()
          .join(' ');
        setError(messages || 'Não foi possível enviar as fotos.');
      } else if (status === 413) {
        setError(
          'O lote de fotos é grande demais para enviar de uma vez. Tente enviar menos fotos por vez (ex.: 5 a 10).',
        );
      } else {
        setError('Não foi possível enviar as fotos. Tente novamente.');
      }
    } finally {
      setIsUploading(false);
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
          <div className={styles.progressBar}>
            <div className={styles.progressFill} style={{ width: `${progress}%` }} />
          </div>
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
