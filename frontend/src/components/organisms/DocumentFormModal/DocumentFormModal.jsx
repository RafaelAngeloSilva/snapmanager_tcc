import { useEffect, useRef, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Select from '../../atoms/Select';
import Textarea from '../../atoms/Textarea';
import FormField from '../../molecules/FormField';
import ClientPicker from '../../molecules/ClientPicker';
import DateTimePicker from '../../molecules/DateTimePicker';
import SignersForm, { EMPTY_SIGNER } from '../SignersForm';
import { createDocument, fetchDocumentLinkOptions, updateDocument } from '../../../api/documents';
import { dateValueIn, formatDateTime, formatDueDate } from '../../../utils/formatters';
import { ACCEPTED_UPLOAD, DOCUMENT_TYPES, apiErrorMessage, fieldErrors, formatFileSize, uploadTypeOf } from '../../../utils/documents';
import styles from './DocumentFormModal.module.css';

const FORM_ID = 'document-form';
const MAX_FILE_BYTES = 10 * 1024 * 1024;

const EMPTY_FORM = {
  title: '',
  type: 'photoshoot',
  order_id: '',
  appointment_id: '',
  expires_at: '',
  notes: '',
};

/**
 * Novo documento (com signatários) ou edição de um rascunho. Ao salvar, o
 * documento fica como RASCUNHO — nada é enviado à Clicksign aqui. Na
 * edição os signatários são gerenciados na tela de detalhes.
 *
 * `initialValues` pré-seleciona o contexto quando o documento nasce de um
 * pedido ou agendamento: { client: {id, name}, order: {id, title} | null,
 * appointment: {id, title, starts_at} | null }. Deve ser um objeto estável
 * (useMemo) — ele reinicia o formulário quando muda.
 * Título, tipo e PDF continuam a cargo de quem preenche. O back-end ainda
 * valida que pedido/agendamento pertencem ao cliente.
 */
export default function DocumentFormModal({ isOpen, document, initialValues = null, onClose, onSaved }) {
  const isEditing = Boolean(document);
  const fileInputRef = useRef(null);

  const [form, setForm] = useState(EMPTY_FORM);
  const [client, setClient] = useState(null);
  const [file, setFile] = useState(null);
  const [signers, setSigners] = useState([{ ...EMPTY_SIGNER }]);
  const [linkOptions, setLinkOptions] = useState({ orders: [], appointments: [] });
  const [isLoadingOptions, setIsLoadingOptions] = useState(false);
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (!isOpen) return;

    if (document) {
      setForm({
        title: document.title,
        type: document.type,
        order_id: document.order?.id ?? '',
        appointment_id: document.appointment?.id ?? '',
        expires_at: document.expires_at || '',
        notes: document.notes || '',
      });
      setClient(document.client);
    } else {
      setForm({
        ...EMPTY_FORM,
        order_id: initialValues?.order?.id ?? '',
        appointment_id: initialValues?.appointment?.id ?? '',
      });
      setClient(initialValues?.client ?? null);
      setSigners([{ ...EMPTY_SIGNER }]);
    }

    setFile(null);
    setErrors({});
    setGeneralError('');
  }, [isOpen, document, initialValues]);

  // Pedidos/agendamentos do cliente escolhido — só registros dele podem ser vinculados.
  useEffect(() => {
    if (!isOpen || !client) {
      setLinkOptions({ orders: [], appointments: [] });
      return undefined;
    }

    let active = true;
    setIsLoadingOptions(true);

    fetchDocumentLinkOptions(client.id)
      .then((options) => active && setLinkOptions(options))
      .catch(() => active && setLinkOptions({ orders: [], appointments: [] }))
      .finally(() => active && setIsLoadingOptions(false));

    return () => {
      active = false;
    };
  }, [isOpen, client]);

  function handleChange(field) {
    return (event) => setForm((prev) => ({ ...prev, [field]: event.target.value }));
  }

  function handleClientChange(nextClient) {
    setClient(nextClient);
    // Vínculos do cliente anterior não valem para o novo.
    setForm((prev) => ({ ...prev, order_id: '', appointment_id: '' }));
  }

  function handleFileChange(event) {
    const selected = event.target.files?.[0] || null;
    setErrors((prev) => ({ ...prev, file: undefined }));

    // Checagem rápida só para avisar cedo; quem decide é o back-end
    // (extensão, tipo real, estrutura do Word).
    const reject = (message) => {
      setErrors((prev) => ({ ...prev, file: message }));
      setFile(null);
      event.target.value = '';
    };

    if (selected && !uploadTypeOf(selected.name)) {
      reject('Formato não aceito. Envie um PDF ou um documento Word (.docx).');
      return;
    }

    if (selected && selected.size > MAX_FILE_BYTES) {
      reject('O arquivo pode ter no máximo 10 MB.');
      return;
    }

    setFile(selected);
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setErrors({});
    setGeneralError('');

    const localErrors = {};
    if (!client) localErrors.client_id = 'Selecione um cliente.';
    if (!isEditing && !file) localErrors.file = 'Envie o contrato em PDF ou Word (.docx).';

    if (Object.keys(localErrors).length) {
      setErrors(localErrors);
      return;
    }

    setIsSubmitting(true);

    const fields = {
      title: form.title,
      type: form.type,
      client_id: client.id,
      order_id: form.order_id || null,
      appointment_id: form.appointment_id || null,
      expires_at: form.expires_at || null,
      notes: form.notes || null,
      file: file || undefined,
    };

    if (!isEditing) {
      fields.signers = signers.map((signer) => ({
        name: signer.name,
        channel: signer.channel,
        email: signer.channel === 'email' ? signer.email : undefined,
        phone_number: signer.channel === 'whatsapp' ? signer.phone_number : undefined,
      }));
    }

    try {
      const saved = isEditing ? await updateDocument(document.id, fields) : await createDocument(fields);
      onSaved(saved, isEditing);
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(fieldErrors(err));
      } else {
        setGeneralError(apiErrorMessage(err, 'Não foi possível salvar o documento. Tente novamente.'));
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  const selectedType = file ? uploadTypeOf(file.name) : null;

  // O registro pré-selecionado sempre aparece na lista, mesmo que não esteja
  // entre os 50 mais recentes devolvidos por link-options — senão o select
  // mostraria "Nenhum" enquanto o formulário enviaria o id.
  const sameClient = initialValues?.client && client?.id === initialValues.client.id;
  const orderOptions = withPreselected(linkOptions.orders, sameClient ? initialValues.order : null);
  const appointmentOptions = withPreselected(linkOptions.appointments, sameClient ? initialValues.appointment : null);

  return (
    <Modal
      isOpen={isOpen}
      onClose={isSubmitting ? undefined : onClose}
      title={isEditing ? 'Editar rascunho' : 'Novo documento'}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button type="submit" form={FORM_ID} isLoading={isSubmitting}>
            {isSubmitting ? (
              <>
                <Spinner size="sm" />
                {selectedType === 'docx' ? 'Salvando e convertendo...' : null}
              </>
            ) : (
              'Salvar rascunho'
            )}
          </Button>
        </>
      }
    >
      <form id={FORM_ID} className={styles.form} onSubmit={handleSubmit} noValidate>
        {generalError && <div className={styles.generalError}>{generalError}</div>}

        <div className={styles.grid}>
          <FormField
            id="document-title"
            label="Título *"
            value={form.title}
            onChange={handleChange('title')}
            error={errors.title}
            placeholder="Ex.: Contrato de ensaio — Maria"
            maxLength={255}
            required
          />

          <FormField id="document-type" label="Tipo *" as={Select} value={form.type} onChange={handleChange('type')} error={errors.type}>
            {DOCUMENT_TYPES.map((type) => (
              <option key={type.value} value={type.value}>
                {type.label}
              </option>
            ))}
          </FormField>
        </div>

        <ClientPicker value={client} onChange={handleClientChange} error={errors.client_id} />

        <div className={styles.grid}>
          <FormField
            id="document-order"
            label="Pedido (opcional)"
            as={Select}
            value={form.order_id}
            onChange={handleChange('order_id')}
            error={errors.order_id}
            disabled={!client || isLoadingOptions}
          >
            <option value="">{client ? 'Nenhum' : 'Selecione o cliente primeiro'}</option>
            {orderOptions.map((order) => (
              <option key={order.id} value={order.id}>
                {order.title}
                {order.due_date ? ` — entrega ${formatDueDate(order.due_date)}` : ''}
              </option>
            ))}
          </FormField>

          <FormField
            id="document-appointment"
            label="Agendamento (opcional)"
            as={Select}
            value={form.appointment_id}
            onChange={handleChange('appointment_id')}
            error={errors.appointment_id}
            disabled={!client || isLoadingOptions}
          >
            <option value="">{client ? 'Nenhum' : 'Selecione o cliente primeiro'}</option>
            {appointmentOptions.map((appointment) => (
              <option key={appointment.id} value={appointment.id}>
                {appointment.title}
                {appointment.starts_at ? ` — ${formatDateTime(appointment.starts_at)}` : ''}
              </option>
            ))}
          </FormField>
        </div>

        <div className={styles.fileField}>
          <span className={styles.label}>{isEditing ? 'Substituir contrato (opcional)' : 'Contrato *'}</span>
          <p className={styles.hint}>
            Envie um PDF ou documento Word (.docx). Arquivos Word serão convertidos automaticamente para PDF antes da assinatura.
          </p>
          <div className={`${styles.fileBox} ${errors.file ? styles.fileBoxInvalid : ''}`}>
            <input
              ref={fileInputRef}
              id="document-file"
              type="file"
              accept={ACCEPTED_UPLOAD}
              onChange={handleFileChange}
              className={styles.fileInput}
            />
            <Button variant="secondary" onClick={() => fileInputRef.current?.click()}>
              Escolher arquivo
            </Button>
            <span className={styles.fileName}>
              {file ? (
                <>
                  <strong>{file.name}</strong>
                  <span className={styles.fileMeta}>
                    {selectedType === 'docx' ? 'Word' : 'PDF'} · {formatFileSize(file.size)}
                    {selectedType === 'docx' && ' · será convertido para PDF ao salvar'}
                  </span>
                </>
              ) : isEditing ? (
                `Atual: ${document.file.name} (${document.file.type_label || 'PDF'} · ${formatFileSize(document.file.size)})`
              ) : (
                'Nenhum arquivo selecionado — PDF ou Word (.docx), até 10 MB'
              )}
            </span>
          </div>
          {errors.file && <span className={styles.error}>{errors.file}</span>}
        </div>

        <div className={styles.grid}>
          <DateTimePicker
            id="document-expires"
            label="Prazo para assinatura (opcional)"
            mode="date"
            value={form.expires_at}
            onChange={(value) => setForm((prev) => ({ ...prev, expires_at: value }))}
            error={errors.expires_at}
            min={dateValueIn(1)}
            max={dateValueIn(90)}
            hint="Entre amanhã e 90 dias — limite da Clicksign."
          />
        </div>

        <FormField
          id="document-notes"
          label="Observações"
          as={Textarea}
          rows={3}
          value={form.notes}
          onChange={handleChange('notes')}
          error={errors.notes}
        />

        {!isEditing && <SignersForm signers={signers} errors={errors} onChange={setSigners} />}
      </form>
    </Modal>
  );
}

function withPreselected(options, preselected) {
  if (!preselected || options.some((option) => option.id === preselected.id)) {
    return options;
  }

  return [preselected, ...options];
}
