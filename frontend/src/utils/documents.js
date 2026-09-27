import { downloadOriginalFile, downloadSignedFile, downloadSigningFile } from '../api/documents';

// Labels e variantes visuais do módulo Documentos. Mesmo padrão dos outros
// utilitários de status: o back-end manda o código, a UI traduz. As regras
// de "quem pode fazer o quê" NÃO ficam aqui — vêm prontas do back-end em
// `document.abilities`.

export const DOCUMENT_TYPES = [
  { value: 'monthly_photoshoot', label: 'Ensaio mensal' },
  { value: 'photoshoot', label: 'Ensaio fotográfico' },
  { value: 'wedding', label: 'Casamento' },
  { value: 'image_use_authorization', label: 'Autorização de uso de imagem' },
  { value: 'custom', label: 'Personalizado' },
];

export const DOCUMENT_STATUS_BADGE_VARIANT = {
  draft: 'neutral',
  preparing: 'info',
  awaiting_signature: 'info',
  partially_signed: 'medium',
  signed: 'success',
  declined: 'high',
  canceled: 'neutral',
  expired: 'medium',
  integration_error: 'high',
};

// Filtros da listagem → grupos de status definidos no back-end
// (DocumentController::STATUS_GROUPS).
export const DOCUMENT_FILTERS = [
  { value: '', label: 'Todos' },
  { value: 'draft', label: 'Rascunhos' },
  { value: 'awaiting', label: 'Aguardando assinatura' },
  { value: 'signed', label: 'Assinados' },
  { value: 'canceled', label: 'Cancelados' },
  { value: 'error', label: 'Erro' },
];

export const SIGNER_CHANNELS = [
  { value: 'email', label: 'E-mail' },
  { value: 'whatsapp', label: 'WhatsApp' },
];

export const SIGNER_CHANNEL_LABELS = { email: 'E-mail', whatsapp: 'WhatsApp' };

export const SIGNER_STATUS_BADGE_VARIANT = {
  pending: 'neutral',
  signed: 'success',
  declined: 'high',
};

/**
 * Texto do badge de status. Enquanto há assinaturas parciais, mostra a
 * contagem ("1 de 2 assinaturas") — mais útil que o nome do estado. Com
 * todas coletadas mas o envelope ainda não fechado, deixa claro que falta
 * a finalização (só o `closed` da Clicksign conta como concluído).
 */
export function documentStatusText(document) {
  if (document.status === 'partially_signed' && document.signers_count) {
    const { signed_count: signed, signers_count: total } = document;

    return signed >= total ? `${signed} de ${total} — finalizando` : `${signed} de ${total} assinaturas`;
  }

  return document.status_label;
}

export function formatFileSize(bytes) {
  if (!bytes && bytes !== 0) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Telefone só com dígitos (DDD + número) → "(11) 98765-4321". */
export function formatPhone(digits) {
  if (!digits) return '';
  const d = String(digits).replace(/\D/g, '');

  if (d.length === 11) return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
  if (d.length === 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;

  return d;
}

/** Máscara progressiva enquanto a pessoa digita. */
export function maskPhone(value) {
  const d = String(value).replace(/\D/g, '').slice(0, 11);

  if (d.length <= 2) return d.length ? `(${d}` : '';
  if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`;
  if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;

  return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
}

/** Nomes das ações — os MESMOS em todas as telas (tabela, detalhes, Pedidos, Agenda). */
export const DOCUMENT_ACTION_LABELS = {
  view: 'Visualizar',
  sync: 'Sincronizar status',
  downloadOriginal: 'Baixar original',
  downloadSigned: 'Baixar assinado',
  previewSigning: 'Visualizar PDF',
  downloadSigning: 'Baixar PDF',
  retryConversion: 'Converter novamente',
};

/** Formatos aceitos no upload — os mesmos do back-end (sem .doc/.docm/.rtf/.odt). */
export const ACCEPTED_UPLOAD = '.pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document';

/** 'pdf' | 'docx' | null a partir do nome do arquivo escolhido. */
export function uploadTypeOf(fileName) {
  const extension = String(fileName).split('.').pop().toLowerCase();

  return ['pdf', 'docx'].includes(extension) ? extension : null;
}

// Estados em que sincronizar não muda mais nada (processo encerrado sem assinatura).
const SYNC_POINTLESS = ['canceled', 'declined', 'expired'];
// Estados em que sincronizar é a ação principal: há assinatura pendente.
const SYNC_PRIMARY = ['preparing', 'awaiting_signature', 'partially_signed', 'integration_error'];

/**
 * Quais ações rápidas mostrar para um documento, e com que destaque.
 *
 * A PERMISSÃO vem sempre do back-end (`document.abilities`, que já combina
 * Policy + estado): esta função só decide visibilidade/ênfase por status e
 * nunca libera algo que o back-end negou. Resumo:
 *  - rascunho: sem envelope → sem sincronizar; sem PDF final;
 *  - aguardando / parcialmente assinado / erro: sincronizar em destaque;
 *  - assinado: "Baixar assinado" em destaque, sincronizar como secundária;
 *  - cancelado / recusado / expirado: sem sincronizar.
 */
export function documentActions(document) {
  const abilities = document?.abilities || {};
  const canSync = Boolean(abilities.sync) && !SYNC_POINTLESS.includes(document.status);

  return {
    canDownloadOriginal: Boolean(abilities.download_original),
    canDownloadSigned: Boolean(abilities.download_signed),
    canSync,
    syncIsPrimary: canSync && SYNC_PRIMARY.includes(document.status),
  };
}

/**
 * Aviso depois de salvar um rascunho. Se era Word e a conversão falhou, o
 * rascunho foi salvo mesmo assim — o aviso diz isso e o que fazer.
 */
export function savedNotice(saved, wasEditing) {
  if (saved?.conversion?.status === 'failed') {
    return {
      tone: 'error',
      message: 'O rascunho foi salvo, mas a conversão do Word para PDF falhou. Abra o documento para ver o motivo e tentar de novo — ele não pode ser enviado até lá.',
    };
  }

  if (saved?.conversion?.status === 'converted') {
    return { tone: 'success', message: 'Word convertido para PDF e rascunho salvo. Confira o PDF para assinatura antes de enviar.' };
  }

  return {
    tone: 'success',
    message: wasEditing ? 'Rascunho atualizado.' : 'Documento salvo como rascunho. Revise os signatários e envie para assinatura.',
  };
}

/** "0 de 1 assinatura", "2 de 2 assinaturas". */
export function signaturesText(document) {
  const total = document.signers_count ?? 0;
  const signed = document.signed_count ?? 0;

  return `${signed} de ${total} ${total === 1 ? 'assinatura' : 'assinaturas'}`;
}

// Cada "kind" de arquivo: de onde vem e com que nome é salvo.
//  - original: o arquivo enviado (PDF ou Word — extensão conforme o formato);
//  - signing:  o PDF que vai para a Clicksign (convertido, se o original é Word);
//  - signed:   o documento final da Clicksign.
const FILE_KINDS = {
  original: { fetch: downloadOriginalFile, suffix: 'original', extension: (document) => document.file?.type || 'pdf' },
  signing: { fetch: downloadSigningFile, suffix: 'para-assinatura', extension: () => 'pdf' },
  signed: { fetch: downloadSignedFile, suffix: 'assinado', extension: () => 'pdf' },
};

function fileBaseName(document) {
  return document.title.replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '').toLowerCase() || 'documento';
}

/**
 * Baixa um arquivo do documento pelo Laravel (que confere a permissão) e
 * salva no computador. Usado pela tabela, pelos detalhes e pela seção de
 * documentos vinculados de Pedidos/Agenda. Documentos antigos (sem
 * `file.type`) continuam saindo como .pdf.
 */
export async function saveDocumentFile(document, kind) {
  const config = FILE_KINDS[kind] || FILE_KINDS.original;
  const blob = await config.fetch(document.id);

  downloadBlob(blob, `${fileBaseName(document)}-${config.suffix}.${config.extension(document)}`);
}

/**
 * Abre o PDF para assinatura numa nova aba, para o funcionário revisar a
 * conversão antes de enviar. A aba é aberta já no clique (senão o
 * navegador bloqueia como pop-up) e recebe o PDF quando ele chegar.
 */
export async function previewSigningPdf(document) {
  const tab = window.open('', '_blank');

  try {
    const blob = await downloadSigningFile(document.id);
    const url = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }));

    if (tab) {
      tab.location.href = url;
    } else {
      downloadBlob(blob, `${fileBaseName(document)}-para-assinatura.pdf`);
    }

    // Tempo de sobra para a aba carregar antes de liberar a memória.
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (err) {
    tab?.close();
    throw err;
  }
}

/** Salva um Blob como arquivo, sem abrir a URL do back-end no navegador. */
export function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');

  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();

  // Libera a memória depois que o navegador iniciou o download.
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/**
 * Mensagem amigável a partir de um erro do axios. Para 422, junta os erros
 * de campo; para os demais, usa a `message` que o back-end já manda pronta.
 */
export function apiErrorMessage(err, fallback = 'Não foi possível concluir a operação. Tente novamente.') {
  const data = err?.response?.data;

  // Download (responseType blob): a mensagem de erro chega como Blob.
  if (data instanceof Blob) return fallback;

  if (err?.response?.status === 403) return 'Você não tem permissão para esta ação.';

  return data?.message || fallback;
}

/**
 * Igual a apiErrorMessage, mas também lê a mensagem de erros de download
 * (que chegam como Blob por causa do responseType: 'blob').
 */
export async function downloadErrorMessage(err, fallback) {
  const data = err?.response?.data;

  if (data instanceof Blob) {
    try {
      return JSON.parse(await data.text()).message || fallback;
    } catch {
      return fallback;
    }
  }

  return apiErrorMessage(err, fallback);
}

/** Converte a resposta 422 do Laravel em { campo: 'primeira mensagem' }. */
export function fieldErrors(err) {
  const errors = err?.response?.data?.errors || {};

  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]));
}
