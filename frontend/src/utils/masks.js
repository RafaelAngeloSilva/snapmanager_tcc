/**
 * Máscara progressiva de CEP (00000-000), usada tanto para digitação quanto
 * para exibir um zip_code já salvo (o back-end guarda só os 8 dígitos).
 */
export function maskCep(value) {
  const digits = (value || '').replace(/\D/g, '').slice(0, 8);

  return digits.replace(/(\d{5})(\d{1,3})$/, '$1-$2');
}
