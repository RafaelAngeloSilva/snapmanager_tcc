import { useEffect, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Select from '../../atoms/Select';
import Textarea from '../../atoms/Textarea';
import FormField from '../../molecules/FormField';
import { createClient, updateClient } from '../../../api/clients';
import { fetchAddressByCep } from '../../../api/cep';
import { maskDocument, cpfHelperMessage } from '../../../utils/cpf';
import { maskCep } from '../../../utils/masks';
import styles from './ClientFormModal.module.css';
import { FIELD_LIMITS } from '../../../utils/fieldLimits';

const BRAZILIAN_STATES = [
  'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
  'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
];

const EMPTY_FORM = {
  name: '',
  email: '',
  phone: '',
  document: '',
  zip_code: '',
  street: '',
  number: '',
  complement: '',
  neighborhood: '',
  city: '',
  state: '',
  notes: '',
};
const FORM_ID = 'client-form';

export default function ClientFormModal({ isOpen, client, onClose, onSaved }) {
  const isEditing = Boolean(client);

  const [form, setForm] = useState(EMPTY_FORM);
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [cepStatus, setCepStatus] = useState({ isLoading: false, error: '' });

  useEffect(() => {
    if (!isOpen) return;

    setForm(
      client
        ? {
            name: client.name || '',
            email: client.email || '',
            phone: client.phone || '',
            document: client.document ? maskDocument(client.document) : '',
            zip_code: client.zip_code ? maskCep(client.zip_code) : '',
            street: client.street || '',
            number: client.number || '',
            complement: client.complement || '',
            neighborhood: client.neighborhood || '',
            city: client.city || '',
            state: client.state || '',
            notes: client.notes || '',
          }
        : EMPTY_FORM,
    );
    setErrors({});
    setGeneralError('');
    setCepStatus({ isLoading: false, error: '' });
  }, [isOpen, client]);

  function handleChange(field) {
    return (event) => setForm((prev) => ({ ...prev, [field]: event.target.value }));
  }

  function handleDocumentChange(event) {
    setForm((prev) => ({ ...prev, document: maskDocument(event.target.value) }));
  }

  async function lookupCep(rawCep) {
    const digits = rawCep.replace(/\D/g, '');

    if (digits.length !== 8) return;

    setCepStatus({ isLoading: true, error: '' });

    try {
      const address = await fetchAddressByCep(digits);
      setForm((prev) => ({
        ...prev,
        street: address.street || prev.street,
        neighborhood: address.neighborhood || prev.neighborhood,
        city: address.city || prev.city,
        state: address.state || prev.state,
      }));
      setCepStatus({ isLoading: false, error: '' });
    } catch (err) {
      const status = err.response?.status;
      let message = 'Não foi possível consultar o CEP agora. Preencha o endereço manualmente.';

      if (status === 422) message = 'CEP inválido. Informe 8 dígitos.';
      if (status === 404) message = 'CEP não encontrado.';

      setCepStatus({ isLoading: false, error: message });
    }
  }

  function handleCepChange(event) {
    const masked = maskCep(event.target.value);
    setForm((prev) => ({ ...prev, zip_code: masked }));
    setCepStatus({ isLoading: false, error: '' });

    if (masked.replace(/\D/g, '').length === 8) {
      lookupCep(masked);
    }
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setErrors({});
    setGeneralError('');
    setIsSubmitting(true);

    const payload = { ...form };

    try {
      const saved = isEditing ? await updateClient(client.id, payload) : await createClient(payload);
      onSaved(saved);
    } catch (err) {
      if (err.response?.status === 422) {
        const fieldErrors = {};

        Object.entries(err.response.data.errors || {}).forEach(([field, messages]) => {
          fieldErrors[field] = messages[0];
        });

        setErrors(fieldErrors);
      } else {
        setGeneralError('Não foi possível salvar o cliente. Tente novamente.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  const cpfHelper = cpfHelperMessage(form.document);

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? 'Editar cliente' : 'Novo cliente'}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button type="submit" form={FORM_ID} isLoading={isSubmitting}>
            {isSubmitting ? <Spinner size="sm" /> : 'Salvar'}
          </Button>
        </>
      }
    >
      <form id={FORM_ID} className={styles.form} onSubmit={handleSubmit}>
        {generalError && <div className={styles.generalError}>{generalError}</div>}

        <FormField
          id="client-name"
          maxLength={FIELD_LIMITS.PERSON_NAME}
          label="Nome *"
          value={form.name}
          onChange={handleChange('name')}
          error={errors.name}
          required
        />

        <div className={styles.grid}>
          <FormField
            id="client-email"
            maxLength={FIELD_LIMITS.EMAIL}
            label="E-mail"
            type="email"
            value={form.email}
            onChange={handleChange('email')}
            error={errors.email}
          />
          <FormField
            id="client-phone"
            maxLength={FIELD_LIMITS.PHONE}
            label="Telefone"
            value={form.phone}
            onChange={handleChange('phone')}
            error={errors.phone}
          />
        </div>

        <div>
          <FormField
            id="client-document"
            label="Documento (CPF/CNPJ)"
            value={form.document}
            onChange={handleDocumentChange}
            error={errors.document}
            placeholder="000.000.000-00"
            inputMode="numeric"
          />
          {cpfHelper && !errors.document && (
            <p className={cpfHelper.tone === 'error' ? styles.helperError : cpfHelper.tone === 'success' ? styles.helperSuccess : styles.helperInfo}>
              {cpfHelper.text}
            </p>
          )}
        </div>

        <p className={styles.sectionTitle}>Endereço</p>

        <div className={styles.grid}>
          <div>
            <FormField
              id="client-zip-code"
              label="CEP"
              value={form.zip_code}
              onChange={handleCepChange}
              error={errors.zip_code || cepStatus.error}
              placeholder="00000-000"
              inputMode="numeric"
            />
            {cepStatus.isLoading && (
              <p className={styles.helperInfo}>
                <Spinner size="sm" /> Consultando CEP...
              </p>
            )}
          </div>

          <FormField
            id="client-neighborhood"
            maxLength={FIELD_LIMITS.NEIGHBORHOOD}
            label="Bairro"
            value={form.neighborhood}
            onChange={handleChange('neighborhood')}
            error={errors.neighborhood}
          />
        </div>

        <FormField
          id="client-street"
          maxLength={FIELD_LIMITS.STREET}
          label="Rua"
          value={form.street}
          onChange={handleChange('street')}
          error={errors.street}
        />

        <div className={styles.grid}>
          <FormField
            id="client-number"
            maxLength={FIELD_LIMITS.ADDRESS_NUMBER}
            label="Número"
            value={form.number}
            onChange={handleChange('number')}
            error={errors.number}
          />
          <FormField
            id="client-complement"
            maxLength={FIELD_LIMITS.COMPLEMENT}
            label="Complemento"
            value={form.complement}
            onChange={handleChange('complement')}
            error={errors.complement}
          />
        </div>

        <div className={styles.grid}>
          <FormField
            id="client-city"
            maxLength={FIELD_LIMITS.CITY}
            label="Cidade"
            value={form.city}
            onChange={handleChange('city')}
            error={errors.city}
          />
          <FormField
            id="client-state"
            label="UF"
            as={Select}
            value={form.state}
            onChange={handleChange('state')}
            error={errors.state}
          >
            <option value="">Selecione</option>
            {BRAZILIAN_STATES.map((uf) => (
              <option key={uf} value={uf}>
                {uf}
              </option>
            ))}
          </FormField>
        </div>

        <FormField
          id="client-notes"
          maxLength={FIELD_LIMITS.NOTES}
          label="Observações"
          as={Textarea}
          rows={3}
          value={form.notes}
          onChange={handleChange('notes')}
          error={errors.notes}
        />
      </form>
    </Modal>
  );
}
