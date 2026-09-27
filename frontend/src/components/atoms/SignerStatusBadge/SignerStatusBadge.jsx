import Badge from '../Badge';
import { SIGNER_STATUS_BADGE_VARIANT } from '../../../utils/documents';

export default function SignerStatusBadge({ signer }) {
  return <Badge variant={SIGNER_STATUS_BADGE_VARIANT[signer.status] || 'neutral'}>{signer.status_label}</Badge>;
}
