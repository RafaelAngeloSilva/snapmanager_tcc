import Button from '../../atoms/Button';
import { PlusIcon } from '../../atoms/Icon';
import SignerFields from '../../molecules/SignerFields';
import styles from './SignersForm.module.css';

export const EMPTY_SIGNER = { name: '', channel: 'email', email: '', phone_number: '' };

export const MAX_SIGNERS = 10;

/**
 * Lista editável de signatários (formulário de novo documento).
 * `errors` vem no formato do Laravel: { 'signers.0.email': 'mensagem' }.
 */
export default function SignersForm({ signers, errors = {}, onChange }) {
  function errorsFor(index) {
    const prefix = `signers.${index}.`;

    return Object.fromEntries(
      Object.entries(errors)
        .filter(([key]) => key.startsWith(prefix))
        .map(([key, message]) => [key.slice(prefix.length), message]),
    );
  }

  function updateAt(index, signer) {
    onChange(signers.map((current, i) => (i === index ? signer : current)));
  }

  function removeAt(index) {
    onChange(signers.filter((_, i) => i !== index));
  }

  return (
    <fieldset className={styles.fieldset}>
      <legend className={styles.legend}>Signatários</legend>
      <p className={styles.hint}>
        Cada signatário recebe o convite e o código de confirmação pelo canal escolhido. Os dados não podem ser alterados
        depois do envio à Clicksign.
      </p>

      {signers.length === 0 && <p className={styles.empty}>Nenhum signatário ainda — adicione antes de enviar.</p>}

      <div className={styles.list}>
        {signers.map((signer, index) => (
          <SignerFields
            // eslint-disable-next-line react/no-array-index-key
            key={index}
            index={index}
            signer={signer}
            errors={errorsFor(index)}
            onChange={(value) => updateAt(index, value)}
            onRemove={() => removeAt(index)}
            canRemove
          />
        ))}
      </div>

      {errors.signers && <p className={styles.error}>{errors.signers}</p>}

      {signers.length < MAX_SIGNERS && (
        <Button variant="secondary" onClick={() => onChange([...signers, { ...EMPTY_SIGNER }])}>
          <PlusIcon />
          Adicionar signatário
        </Button>
      )}
    </fieldset>
  );
}
