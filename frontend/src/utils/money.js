/**
 * Valor em reais — mesma regra do back-end (backend/app/Rules/Money.php):
 * não negativo, no máximo 2 casas decimais, até 99.999.999,99.
 *
 * O Laravel continua sendo quem decide; aqui é só para avisar o usuário
 * ANTES de enviar e para nunca mandar "-0", "1e5", NaN ou Infinity.
 */
export const MONEY_MAX = 99999999.99;

const MONEY_PATTERN = /^\d{1,8}(\.\d{1,2})?$/;

/**
 * Normaliza o texto digitado num campo de valor.
 *
 * Aceita vírgula como separador decimal ("1500,50"), apara espaços e trata
 * "-0" / "-0,00" como 0. Devolve { value, error }: `value` é a string pronta
 * para a API ("1500.50") ou null quando vazio; `error` explica o problema.
 */
export function parseMoney(raw) {
  const text = String(raw ?? '').trim().replace(',', '.');

  if (text === '') {
    return { value: null, error: null };
  }

  if (/^-0+(\.0+)?$/.test(text)) {
    return { value: '0', error: null };
  }

  if (text.startsWith('-')) {
    return { value: null, error: 'O valor não pode ser negativo.' };
  }

  if (!MONEY_PATTERN.test(text)) {
    const number = Number(text);

    if (Number.isFinite(number) && number > MONEY_MAX) {
      return { value: null, error: 'O valor máximo permitido é R$ 99.999.999,99.' };
    }

    return { value: null, error: 'Informe um valor válido, com até 2 casas decimais (ex.: 1500,00).' };
  }

  if (Number(text) > MONEY_MAX) {
    return { value: null, error: 'O valor máximo permitido é R$ 99.999.999,99.' };
  }

  return { value: text, error: null };
}
