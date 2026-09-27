/**
 * "Foto 001" — três dígitos, para facilitar a comunicação entre cliente e
 * funcionário sobre qual foto é qual. Espera o número de posição já
 * calculado pelo back-end (GalleryImage::assignDisplayNumbers) — nunca o
 * "sort_order" bruto, que pode ter buracos se uma foto no meio for excluída.
 */
export function formatPhotoNumber(displayNumber) {
  if (!displayNumber) return null;

  return `Foto ${String(displayNumber).padStart(3, '0')}`;
}
