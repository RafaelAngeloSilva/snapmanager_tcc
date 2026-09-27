import { useEffect, useMemo, useState } from 'react';
import Modal from '../../atoms/Modal';
import Button from '../../atoms/Button';
import Spinner from '../../atoms/Spinner';
import Select from '../../atoms/Select';
import Textarea from '../../atoms/Textarea';
import Checkbox from '../../atoms/Checkbox';
import FormField from '../../molecules/FormField';
import ClientPicker from '../../molecules/ClientPicker';
import DateTimePicker from '../../molecules/DateTimePicker';
import { createGallery, updateGallery } from '../../../api/galleries';
import { fetchOrders } from '../../../api/orders';
import { datetimeLocalToIso, datetimeLocalValueIn, nowAsDatetimeLocalValue, toDatetimeLocalValue } from '../../../utils/formatters';
import styles from './GalleryFormModal.module.css';

const DEFAULT_WATERMARK_TEXT = 'PROIBIDO REPRODUÇÃO';

const EMPTY_FORM = {
  order_id: '',
  title: '',
  description: '',
  expires_at: '',
  watermark_enabled: true,
  watermark_type: 'text',
  watermark_text: DEFAULT_WATERMARK_TEXT,
};
const FORM_ID = 'gallery-form';

export default function GalleryFormModal({ isOpen, gallery, onClose, onSaved }) {
  const isEditing = Boolean(gallery);

  const [selectedClient, setSelectedClient] = useState(null);
  const [orders, setOrders] = useState([]);
  const [form, setForm] = useState(EMPTY_FORM);
  const [errors, setErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Mesma lógica do agendamento/pedido: não força "min" se a galeria em
  // edição já expirou naturalmente — isso é um estado normal, e a galeria
  // precisa continuar editável nos outros campos sem ser forçada a mudar a
  // expiração. O back-end só valida "não pode ser passado" quando a data
  // está realmente mudando.
  const nowLocal = nowAsDatetimeLocalValue();
  const expiresAtMin =
    !isEditing || (gallery?.expires_at && toDatetimeLocalValue(gallery.expires_at) >= nowLocal) ? nowLocal : undefined;

  useEffect(() => {
    if (!isOpen) return;

    if (gallery) {
      setSelectedClient(gallery.client || null);
      setForm({
        order_id: gallery.order?.id ?? '',
        title: gallery.title || '',
        description: gallery.description || '',
        expires_at: gallery.expires_at ? toDatetimeLocalValue(gallery.expires_at) : '',
        watermark_enabled: gallery.watermark_enabled ?? true,
        watermark_type: gallery.watermark_type || 'text',
        watermark_text: gallery.watermark_text || DEFAULT_WATERMARK_TEXT,
      });
    } else {
      setSelectedClient(null);
      setForm(EMPTY_FORM);
    }

    setErrors({});
    setGeneralError('');
  }, [isOpen, gallery]);

  // Só pedidos do cliente selecionado fazem sentido como vínculo.
  useEffect(() => {
    if (!selectedClient) {
      setOrders([]);
      return;
    }

    fetchOrders({ page: 1, clientId: selectedClient.id })
      .then((response) => setOrders(response.data))
      .catch(() => setOrders([]));
  }, [selectedClient]);

  const orderOptions = useMemo(() => {
    if (!gallery?.order) return orders;

    const alreadyIncluded = orders.some((order) => order.id === gallery.order.id);

    return alreadyIncluded ? orders : [gallery.order, ...orders];
  }, [orders, gallery]);

  function handleChange(field) {
    return (event) => setForm((prev) => ({ ...prev, [field]: event.target.value }));
  }

  function handleWatermarkToggle() {
    setForm((prev) => ({ ...prev, watermark_enabled: !prev.watermark_enabled }));
  }

  function handleClientChange(client) {
    setSelectedClient(client);
    setForm((prev) => ({ ...prev, order_id: '' }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setErrors({});
    setGeneralError('');

    if (!selectedClient) {
      setErrors({ client_id: 'Selecione um cliente.' });
      return;
    }

    if (expiresAtMin && form.expires_at && form.expires_at < expiresAtMin) {
      setErrors({ expires_at: 'A data de expiração da galeria não pode ser anterior à data atual.' });
      return;
    }

    setIsSubmitting(true);

    const payload = {
      client_id: selectedClient.id,
      order_id: form.order_id || null,
      title: form.title,
      description: form.description || null,
      // Manda o INSTANTE escolhido (ISO com fuso), não o horário "solto":
      // o back-end roda em UTC e leria "18:00" como 18:00 UTC — 15:00 em
      // Brasília. Assim o horário escolhido é o horário gravado.
      expires_at: datetimeLocalToIso(form.expires_at),
      watermark_enabled: form.watermark_enabled,
      watermark_type: form.watermark_type,
      watermark_text: form.watermark_type === 'text' ? form.watermark_text || DEFAULT_WATERMARK_TEXT : null,
    };

    try {
      const saved = isEditing ? await updateGallery(gallery.id, payload) : await createGallery(payload);
      onSaved(saved);
    } catch (err) {
      if (err.response?.status === 422) {
        const fieldErrors = {};

        Object.entries(err.response.data.errors || {}).forEach(([field, messages]) => {
          fieldErrors[field] = messages[0];
        });

        setErrors(fieldErrors);
      } else {
        setGeneralError('Não foi possível salvar a galeria. Tente novamente.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? 'Editar galeria' : 'Nova galeria'}
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

        <ClientPicker value={selectedClient} onChange={handleClientChange} error={errors.client_id} />

        <FormField
          id="gallery-title"
          label="Título *"
          value={form.title}
          onChange={handleChange('title')}
          error={errors.title}
          placeholder="Ex.: Ensaio de casamento - Ana e Bruno"
          required
        />

        <div className={styles.grid}>
          <FormField
            id="gallery-order"
            label="Pedido relacionado"
            as={Select}
            value={form.order_id}
            onChange={handleChange('order_id')}
            error={errors.order_id}
            disabled={!selectedClient}
          >
            <option value="">Nenhum</option>
            {orderOptions.map((order) => (
              <option key={order.id} value={order.id}>
                {order.title}
              </option>
            ))}
          </FormField>

          <DateTimePicker
            id="gallery-expires-at"
            label="Expira em"
            value={form.expires_at}
            onChange={(value) => setForm((prev) => ({ ...prev, expires_at: value }))}
            error={errors.expires_at}
            min={expiresAtMin}
            hint="Sem data, a galeria fica disponível até ser encerrada."
            quickActions={[
              {
                label: 'Expirar em 48 horas',
                hint: 'Preenche com a data e a hora de agora + 48 horas',
                // Soma sobre o instante atual (não sobre a data escolhida):
                // só preenche o campo, e o funcionário pode trocar depois.
                onSelect: () => setForm((prev) => ({ ...prev, expires_at: datetimeLocalValueIn(48) })),
              },
            ]}
          />
        </div>

        <div className={styles.watermarkBox}>
          <label className={styles.checkboxRow}>
            <Checkbox checked={form.watermark_enabled} onChange={handleWatermarkToggle} />
            <span>Aplicar marca d'água nas fotos da galeria</span>
          </label>

          {form.watermark_enabled && (
            <div className={styles.watermarkFields}>
              <FormField
                id="gallery-watermark-type"
                label="Tipo de marca d'água"
                as={Select}
                value={form.watermark_type}
                onChange={handleChange('watermark_type')}
                error={errors.watermark_type}
              >
                <option value="text">Texto genérico</option>
                <option value="image">Imagem personalizada</option>
              </FormField>

              {form.watermark_type === 'text' && (
                <FormField
                  id="gallery-watermark-text"
                  label="Texto da marca d'água"
                  value={form.watermark_text}
                  onChange={handleChange('watermark_text')}
                  error={errors.watermark_text}
                  placeholder={DEFAULT_WATERMARK_TEXT}
                />
              )}

              {form.watermark_type === 'image' && (
                <p className={styles.watermarkHint}>
                  {isEditing
                    ? 'Envie ou troque a imagem da marca d\'água na tela de detalhes da galeria.'
                    : 'Depois de criar a galeria, envie a imagem da marca d\'água na tela de detalhes.'}
                </p>
              )}
            </div>
          )}
        </div>

        <FormField
          id="gallery-description"
          label="Descrição"
          as={Textarea}
          rows={3}
          value={form.description}
          onChange={handleChange('description')}
          error={errors.description}
          placeholder="Detalhes sobre a sessão, instruções para o cliente..."
        />
      </form>
    </Modal>
  );
}
