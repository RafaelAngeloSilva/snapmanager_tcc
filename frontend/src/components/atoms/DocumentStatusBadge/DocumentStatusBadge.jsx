import Badge from '../Badge';
import { DOCUMENT_STATUS_BADGE_VARIANT, documentStatusText } from '../../../utils/documents';

/**
 * Status do documento. Com assinaturas parciais mostra a contagem
 * ("1 de 2 assinaturas") em vez do nome do estado — a não ser que
 * `showCount` seja false, quando quem usa já mostra a contagem ao lado.
 */
export default function DocumentStatusBadge({ document, showCount = true }) {
  return (
    <Badge variant={DOCUMENT_STATUS_BADGE_VARIANT[document.status] || 'neutral'}>
      {showCount ? documentStatusText(document) : document.status_label}
    </Badge>
  );
}
