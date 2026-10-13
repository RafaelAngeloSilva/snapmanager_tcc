/**
 * Limites de tamanho dos campos — ESPELHO de backend/app/Support/FieldLimits.php.
 *
 * O front-end usa estes valores só para conforto do usuário (maxLength,
 * contador de caracteres, aviso antes de enviar). A validação definitiva é
 * sempre do Laravel: mudar um valor aqui sem mudar lá não libera nada.
 */
export const FIELD_LIMITS = Object.freeze({
  PERSON_NAME: 120,
  EMAIL: 254,
  PHONE: 20,

  STREET: 150,
  ADDRESS_NUMBER: 20,
  COMPLEMENT: 100,
  NEIGHBORHOOD: 100,
  CITY: 100,

  TITLE: 150,
  CATEGORY: 80,
  LOCATION: 200,
  KANBAN_COLUMN: 60,
  WATERMARK_TEXT: 60,

  NOTES: 2000,
  DESCRIPTION: 2000,
  DOCUMENT_NOTES: 5000,
  STATUS_NOTE: 500,

  PREFERENCE_NOTES: 1500,
  HISTORY_TITLE: 120,
  HISTORY_NOTES: 2000,

  SEARCH: 100,

  PASSWORD_MIN: 8,
  PASSWORD_MAX: 72,
});

/** A partir deste limite (textos longos: observações, descrições) o FormField
 * mostra o contador "n/máx" sempre; nos campos curtos, só perto do limite. */
export const COUNTER_THRESHOLD = 500;
