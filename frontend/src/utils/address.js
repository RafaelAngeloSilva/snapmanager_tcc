import { maskCep } from './masks';

/**
 * Compõe o endereço estruturado do cliente numa única linha legível, para
 * telas de detalhes/impressão. Omite partes ausentes sem deixar
 * pontuação sobrando.
 */
export function formatFullAddress(client) {
  const streetLine = [client.street, client.number].filter(Boolean).join(', ');
  const complementPart = client.complement ? ` (${client.complement})` : '';
  const cityState = [client.city, client.state].filter(Boolean).join('/');
  const zipPart = client.zip_code ? `CEP ${maskCep(client.zip_code)}` : '';

  const parts = [
    streetLine ? `${streetLine}${complementPart}` : '',
    client.neighborhood,
    cityState,
    zipPart,
  ].filter(Boolean);

  return parts.length > 0 ? parts.join(' - ') : '';
}
