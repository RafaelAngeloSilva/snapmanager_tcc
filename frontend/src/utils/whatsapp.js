import { formatDateTime } from './formatters';

/**
 * Compartilhamento por LINK do WhatsApp (wa.me) — nada de API oficial do
 * WhatsApp Business, nenhum custo e nenhuma mensagem enviada sozinha: o
 * link só abre a conversa com o texto pronto, e quem envia é o funcionário,
 * dentro do próprio WhatsApp.
 */

/**
 * Telefone no formato que o wa.me espera: só dígitos, com DDI.
 * Aceita "(11) 98765-4321", "11987654321", "+55 11 98765-4321".
 * Devolve null quando não dá para montar um número brasileiro válido —
 * nesse caso o compartilhamento vai sem destinatário.
 */
export function normalizeWhatsappPhone(phone) {
  const digits = String(phone ?? '').replace(/\D/g, '');

  if (!digits) return null;

  // Já veio com DDI 55 (12 ou 13 dígitos: 55 + DDD + 8 ou 9 dígitos).
  if (digits.length >= 12 && digits.startsWith('55')) {
    return /^55[1-9]{2}\d{8,9}$/.test(digits) ? digits : null;
  }

  // DDD + número, sem DDI.
  return /^[1-9]{2}\d{8,9}$/.test(digits) ? `55${digits}` : null;
}

/** Mensagem padrão de compartilhamento da galeria. */
export function galleryShareMessage({ clientName, title, url, expiresAt }) {
  const firstName = String(clientName || '').trim().split(/\s+/)[0];
  const greeting = firstName ? `Olá, ${firstName}!` : 'Olá!';
  const gallery = title ? ` "${title}"` : '';

  return [
    `${greeting} Sua galeria de fotos${gallery} está disponível para visualização e seleção.`,
    'Acesse pelo link abaixo:',
    url,
    expiresAt ? `\nO link fica disponível até ${formatDateTime(expiresAt)}.` : '',
  ]
    .filter(Boolean)
    .join('\n');
}

/** Mensagem padrão do link "Acompanhe seu Pedido". */
export function orderTrackingShareMessage({ clientName, title, url }) {
  const firstName = String(clientName || '').trim().split(/\s+/)[0];
  const greeting = firstName ? `Olá, ${firstName}!` : 'Olá!';
  const order = title ? ` "${title}"` : '';

  return [
    `${greeting} Você pode acompanhar o andamento do seu pedido${order} pelo link abaixo:`,
    url,
    '\nSempre que o pedido avançar de etapa, a atualização aparece nessa página.',
  ].join('\n');
}

/** URL do wa.me — com destinatário quando há telefone válido, sem ele caso contrário. */
export function whatsappShareUrl({ phone, message }) {
  const normalized = normalizeWhatsappPhone(phone);
  const text = encodeURIComponent(message);

  return normalized ? `https://wa.me/${normalized}?text=${text}` : `https://wa.me/?text=${text}`;
}
