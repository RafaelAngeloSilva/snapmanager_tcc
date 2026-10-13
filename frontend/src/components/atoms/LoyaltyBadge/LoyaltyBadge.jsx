import { CrownIcon, SproutIcon, StarIcon } from '../Icon';
import styles from './LoyaltyBadge.module.css';

const TIERS = {
  new: { label: 'Novo', Icon: SproutIcon },
  loyal: { label: 'Fiel', Icon: StarIcon },
  vip: { label: 'VIP', Icon: CrownIcon },
};

/**
 * Selo de fidelidade (calculado no back-end a partir dos ensaios
 * concluídos). `title` explica a regra ao passar o mouse.
 */
export default function LoyaltyBadge({ tier = 'new', completed, loyalFrom = 3, vipFrom = 6 }) {
  const { label, Icon } = TIERS[tier] || TIERS.new;
  const rule = `Novo: 0 a ${loyalFrom - 1} ensaios · Fiel: ${loyalFrom} a ${vipFrom - 1} · VIP: ${vipFrom} ou mais`;
  const count = Number.isInteger(completed) ? `${completed} ${completed === 1 ? 'ensaio realizado' : 'ensaios realizados'}. ` : '';

  return (
    <span className={`${styles.badge} ${styles[tier] || ''}`} title={`${count}${rule}`}>
      <Icon width={14} height={14} aria-hidden="true" />
      {label}
    </span>
  );
}
