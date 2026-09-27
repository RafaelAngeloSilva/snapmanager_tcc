import Button from '../../atoms/Button';
import Input from '../../atoms/Input';
import Select from '../../atoms/Select';
import { TrashIcon } from '../../atoms/Icon';
import { SIGNER_CHANNELS, maskPhone } from '../../../utils/documents';
import styles from './SignerFields.module.css';

/**
 * Campos de um signatário no formulário. O campo de contato acompanha o
 * canal: e-mail para "E-mail", telefone para "WhatsApp" — o mesmo que o
 * back-end exige.
 */
export default function SignerFields({ index, signer, errors = {}, onChange, onRemove, canRemove }) {
  const prefix = `signer-${index}`;

  function update(field, value) {
    onChange({ ...signer, [field]: value });
  }

  return (
    <div className={styles.row}>
      <label className={styles.field} htmlFor={`${prefix}-name`}>
        <span className={styles.label}>Nome completo *</span>
        <Input
          id={`${prefix}-name`}
          value={signer.name}
          onChange={(event) => update('name', event.target.value)}
          invalid={Boolean(errors.name)}
          placeholder="Nome e sobrenome"
          autoComplete="off"
        />
        {errors.name && <span className={styles.error}>{errors.name}</span>}
      </label>

      <label className={styles.field} htmlFor={`${prefix}-channel`}>
        <span className={styles.label}>Canal *</span>
        <Select id={`${prefix}-channel`} value={signer.channel} onChange={(event) => update('channel', event.target.value)}>
          {SIGNER_CHANNELS.map((channel) => (
            <option key={channel.value} value={channel.value}>
              {channel.label}
            </option>
          ))}
        </Select>
      </label>

      {signer.channel === 'whatsapp' ? (
        <label className={styles.field} htmlFor={`${prefix}-phone`}>
          <span className={styles.label}>Telefone (WhatsApp) *</span>
          <Input
            id={`${prefix}-phone`}
            type="tel"
            inputMode="numeric"
            value={maskPhone(signer.phone_number || '')}
            onChange={(event) => update('phone_number', event.target.value.replace(/\D/g, ''))}
            invalid={Boolean(errors.phone_number)}
            placeholder="(11) 98765-4321"
          />
          {errors.phone_number && <span className={styles.error}>{errors.phone_number}</span>}
        </label>
      ) : (
        <label className={styles.field} htmlFor={`${prefix}-email`}>
          <span className={styles.label}>E-mail *</span>
          <Input
            id={`${prefix}-email`}
            type="email"
            value={signer.email || ''}
            onChange={(event) => update('email', event.target.value)}
            invalid={Boolean(errors.email)}
            placeholder="nome@exemplo.com"
          />
          {errors.email && <span className={styles.error}>{errors.email}</span>}
        </label>
      )}

      <div className={styles.removeCell}>
        {canRemove && (
          <Button variant="ghost" size="icon" onClick={onRemove} aria-label={`Remover signatário ${index + 1}`}>
            <TrashIcon width={15} height={15} />
          </Button>
        )}
      </div>
    </div>
  );
}
