/**
 * Mesma regra usada no back-end (App\Rules\ValidCpf): dois dígitos
 * verificadores, cada um o resto de uma soma ponderada (pesos 10..2, depois
 * 11..2) módulo 11, mapeado pra 0 quando o resto é 0 ou 1.
 */
function calculateCheckDigit(base, startWeight) {
  let sum = 0;

  for (let i = 0; i < base.length; i += 1) {
    sum += Number(base[i]) * (startWeight - i);
  }

  const remainder = sum % 11;

  return remainder < 2 ? '0' : String(11 - remainder);
}

/**
 * Calcula os dois dígitos verificadores esperados para os 9 primeiros
 * dígitos de um CPF. Usado para o texto de ajuda "Dígitos verificadores
 * esperados: XX" quando o usuário ainda não terminou de digitar.
 */
export function computeCpfCheckDigits(base9) {
  if (base9.length !== 9) return null;

  const firstDigit = calculateCheckDigit(base9, 10);
  const secondDigit = calculateCheckDigit(base9 + firstDigit, 11);

  return firstDigit + secondDigit;
}

export function isValidCpf(digits) {
  if (digits.length !== 11) return false;

  // 000.000.000-00, 111.111.111-11 etc. passam na soma ponderada por
  // construção, então precisam ser excluídos explicitamente.
  if (/^(\d)\1{10}$/.test(digits)) return false;

  const expected = computeCpfCheckDigits(digits.slice(0, 9));

  return digits.slice(9) === expected;
}

/**
 * Máscara progressiva conforme o usuário digita: até 11 dígitos vira CPF
 * (000.000.000-00); de 12 a 14 vira CNPJ (00.000.000/0000-00). Mantém o
 * documento genérico do sistema (aceita CPF ou CNPJ no mesmo campo).
 */
export function maskDocument(value) {
  const digits = value.replace(/\D/g, '').slice(0, 14);

  if (digits.length <= 11) {
    return digits
      .replace(/(\d{3})(\d)/, '$1.$2')
      .replace(/(\d{3})(\d)/, '$1.$2')
      .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
  }

  return digits
    .replace(/(\d{2})(\d)/, '$1.$2')
    .replace(/(\d{3})(\d)/, '$1.$2')
    .replace(/(\d{3})(\d)/, '$1/$2')
    .replace(/(\d{4})(\d{1,2})$/, '$1-$2');
}

/**
 * Texto de ajuda contextual mostrado abaixo do campo de documento, conforme
 * a quantidade de dígitos já digitados. Retorna null quando não há nada
 * relevante a mostrar (documento vazio ou parecendo CNPJ).
 */
/**
 * Mesma formatação de maskDocument, mas usada para exibir um documento já
 * salvo (o back-end normaliza e guarda só os dígitos) em telas de
 * listagem/detalhes — não para o campo de input em edição.
 */
export const formatDocument = maskDocument;

export function cpfHelperMessage(value) {
  const digits = (value || '').replace(/\D/g, '');

  if (digits.length === 0 || digits.length > 11) return null;

  if (digits.length === 9) {
    return { tone: 'info', text: `Dígitos verificadores esperados: ${computeCpfCheckDigits(digits)}` };
  }

  if (digits.length === 11) {
    return isValidCpf(digits)
      ? { tone: 'success', text: 'CPF válido.' }
      : { tone: 'error', text: 'CPF inválido. Verifique os dígitos informados.' };
  }

  return null;
}
