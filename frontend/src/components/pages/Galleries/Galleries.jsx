import { useCallback, useEffect, useState } from 'react';
import { fetchGalleries } from '../../../api/galleries';
import Card from '../../atoms/Card';
import Spinner from '../../atoms/Spinner';
import EmptyState from '../../atoms/EmptyState';
import Button from '../../atoms/Button';
import Pagination from '../../molecules/Pagination';
import GalleriesToolbar from '../../organisms/GalleriesToolbar';
import GalleriesTable from '../../organisms/GalleriesTable';
import GalleryFormModal from '../../organisms/GalleryFormModal';
import GalleryDetailsModal from '../../organisms/GalleryDetailsModal';
import GalleryUploadModal from '../../organisms/GalleryUploadModal';
import CloseGalleryDialog from '../../organisms/CloseGalleryDialog';
import DeleteGalleryDialog from '../../organisms/DeleteGalleryDialog';
import styles from './Galleries.module.css';

export default function Galleries() {
  const [galleries, setGalleries] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [toast, setToast] = useState('');

  const [statusFilter, setStatusFilter] = useState('');
  const [clientFilter, setClientFilter] = useState(null);
  const hasActiveFilters = Boolean(statusFilter || clientFilter);

  const [formModal, setFormModal] = useState({ isOpen: false, gallery: null });
  const [detailsModal, setDetailsModal] = useState({ isOpen: false, galleryId: null });
  const [uploadModal, setUploadModal] = useState({ isOpen: false, gallery: null });
  const [closeDialog, setCloseDialog] = useState({ isOpen: false, gallery: null });
  const [deleteDialog, setDeleteDialog] = useState({ isOpen: false, gallery: null });

  const load = useCallback((targetPage, filters) => {
    setIsLoading(true);
    setError(null);

    fetchGalleries({ page: targetPage, status: filters.status, clientId: filters.clientId })
      .then((response) => {
        setGalleries(response.data);
        setMeta(response.meta);
      })
      .catch(() => setError('Não foi possível carregar as galerias.'))
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    load(1, { status: statusFilter, clientId: clientFilter?.id || '' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter, clientFilter]);

  function currentFilters() {
    return { status: statusFilter, clientId: clientFilter?.id || '' };
  }

  function handleClearFilters() {
    setStatusFilter('');
    setClientFilter(null);
  }

  function handlePageChange(newPage) {
    load(newPage, currentFilters());
  }

  function showToast(message) {
    setToast(message);
    setTimeout(() => setToast(''), 2500);
  }

  function handleCreateClick() {
    setFormModal({ isOpen: true, gallery: null });
  }

  function handleEditFromRow(gallery) {
    setFormModal({ isOpen: true, gallery });
  }

  function handleEditFromDetails(gallery) {
    setDetailsModal({ isOpen: false, galleryId: null });
    setFormModal({ isOpen: true, gallery });
  }

  function handleView(gallery) {
    setDetailsModal({ isOpen: true, galleryId: gallery.id });
  }

  function handleUploadClick(gallery) {
    setUploadModal({ isOpen: true, gallery });
  }

  async function handleCopyLink(gallery) {
    const publicUrl = `${window.location.origin}/public/gallery/${gallery.access_token}`;

    try {
      await navigator.clipboard.writeText(publicUrl);
    } catch {
      window.prompt('Copie o link público:', publicUrl);
    }

    showToast('Link público copiado!');
  }

  function handleCloseClick(gallery) {
    setDetailsModal({ isOpen: false, galleryId: null });
    setCloseDialog({ isOpen: true, gallery });
  }

  function handleDeleteClick(gallery) {
    setDeleteDialog({ isOpen: true, gallery });
  }

  function handleSaved() {
    setFormModal({ isOpen: false, gallery: null });
    load(meta.current_page, currentFilters());
  }

  function handleUploaded() {
    setUploadModal({ isOpen: false, gallery: null });
    load(meta.current_page, currentFilters());
    showToast('Fotos enviadas com sucesso!');
  }

  function handleClosed() {
    setCloseDialog({ isOpen: false, gallery: null });
    load(meta.current_page, currentFilters());
  }

  function handleDeleted() {
    setDeleteDialog({ isOpen: false, gallery: null });

    const willPageBeEmpty = galleries.length === 1 && meta.current_page > 1;
    const targetPage = willPageBeEmpty ? meta.current_page - 1 : meta.current_page;

    load(targetPage, currentFilters());
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Galerias</h1>
        <p className="page-subtitle">Envie fotos para o cliente escolher e acompanhe a seleção.</p>
      </div>

      <GalleriesToolbar
        statusFilter={statusFilter}
        onStatusFilterChange={setStatusFilter}
        clientFilter={clientFilter}
        onClientFilterChange={setClientFilter}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={handleClearFilters}
        onCreateClick={handleCreateClick}
      />

      {toast && <div className={styles.toast}>{toast}</div>}

      <Card padding="none">
        {isLoading && (
          <div className={styles.loadingBox}>
            <Spinner /> Carregando galerias...
          </div>
        )}

        {!isLoading && error && (
          <div className={styles.stateBox}>
            <EmptyState
              tone="error"
              layout="row"
              title="Erro ao carregar galerias"
              message={error}
              action={
                <Button variant="danger" onClick={() => load(meta.current_page, currentFilters())}>
                  Tentar novamente
                </Button>
              }
            />
          </div>
        )}

        {!isLoading && !error && galleries.length === 0 && !hasActiveFilters && (
          <div className={styles.stateBox}>
            <EmptyState
              title="Nenhuma galeria criada"
              message="Crie uma galeria para o cliente escolher suas fotos favoritas."
              action={<Button onClick={handleCreateClick}>Nova galeria</Button>}
            />
          </div>
        )}

        {!isLoading && !error && galleries.length === 0 && hasActiveFilters && (
          <div className={styles.stateBox}>
            <EmptyState title="Nenhum resultado" message="Nenhuma galeria corresponde aos filtros aplicados." />
          </div>
        )}

        {!isLoading && !error && galleries.length > 0 && (
          <GalleriesTable
            galleries={galleries}
            onView={handleView}
            onEdit={handleEditFromRow}
            onUpload={handleUploadClick}
            onCopyLink={handleCopyLink}
            onClose={handleCloseClick}
            onDelete={handleDeleteClick}
          />
        )}
      </Card>

      {!isLoading && !error && (
        <Pagination currentPage={meta.current_page} lastPage={meta.last_page} onPageChange={handlePageChange} />
      )}

      <GalleryFormModal
        isOpen={formModal.isOpen}
        gallery={formModal.gallery}
        onClose={() => setFormModal({ isOpen: false, gallery: null })}
        onSaved={handleSaved}
      />

      <GalleryDetailsModal
        isOpen={detailsModal.isOpen}
        galleryId={detailsModal.galleryId}
        onClose={() => setDetailsModal({ isOpen: false, galleryId: null })}
        onEdit={handleEditFromDetails}
        onCloseGallery={handleCloseClick}
        onChanged={() => load(meta.current_page, currentFilters())}
      />

      <GalleryUploadModal
        isOpen={uploadModal.isOpen}
        gallery={uploadModal.gallery}
        onClose={() => setUploadModal({ isOpen: false, gallery: null })}
        onUploaded={handleUploaded}
      />

      <CloseGalleryDialog
        isOpen={closeDialog.isOpen}
        gallery={closeDialog.gallery}
        onClose={() => setCloseDialog({ isOpen: false, gallery: null })}
        onClosed={handleClosed}
      />

      <DeleteGalleryDialog
        isOpen={deleteDialog.isOpen}
        gallery={deleteDialog.gallery}
        onClose={() => setDeleteDialog({ isOpen: false, gallery: null })}
        onDeleted={handleDeleted}
      />
    </div>
  );
}
