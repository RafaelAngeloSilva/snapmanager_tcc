import apiClient from './client';

// Documentos e assinaturas. O Access Token da Clicksign nunca passa por
// aqui: o React só conversa com o Laravel, que conversa com a Clicksign.

// orderId / appointmentId: seção "Documentos vinculados" de Pedidos e
// Agenda. O back-end continua aplicando a visibilidade por perfil antes
// desses filtros.
export async function fetchDocuments({ page = 1, status = '', search = '', orderId = null, appointmentId = null } = {}) {
  const params = { page };

  if (status) params.status = status;
  if (search) params.search = search;
  if (orderId) params.order_id = orderId;
  if (appointmentId) params.appointment_id = appointmentId;

  const response = await apiClient.get('/documents', { params });

  return response.data;
}

export async function fetchDocument(id) {
  const response = await apiClient.get(`/documents/${id}`);

  return response.data.data;
}

export async function fetchDocumentLinkOptions(clientId) {
  const response = await apiClient.get('/documents/link-options', { params: { client_id: clientId } });

  return response.data;
}

/**
 * Monta o multipart. Arrays de signatários viram `signers[0][name]` etc.,
 * formato que o Laravel entende nativamente.
 */
function toFormData(fields) {
  const form = new FormData();

  Object.entries(fields).forEach(([key, value]) => {
    if (value === undefined) return;

    if (key === 'signers') {
      value.forEach((signer, index) => {
        Object.entries(signer).forEach(([field, fieldValue]) => {
          if (fieldValue !== undefined && fieldValue !== null && fieldValue !== '') {
            form.append(`signers[${index}][${field}]`, fieldValue);
          }
        });
      });

      return;
    }

    form.append(key, value === null ? '' : value);
  });

  return form;
}

export async function createDocument(fields) {
  const response = await apiClient.post('/documents', toFormData(fields));

  return response.data.data;
}

// PHP não lê arquivos em PUT multipart: envia POST com _method=PUT.
export async function updateDocument(id, fields) {
  const response = await apiClient.post(`/documents/${id}`, toFormData({ ...fields, _method: 'PUT' }));

  return response.data.data;
}

export async function deleteDocument(id) {
  await apiClient.delete(`/documents/${id}`);
}

export async function addDocumentSigner(documentId, signer) {
  const response = await apiClient.post(`/documents/${documentId}/signers`, signer);

  return response.data.data;
}

export async function removeDocumentSigner(documentId, signerId) {
  const response = await apiClient.delete(`/documents/${documentId}/signers/${signerId}`);

  return response.data.data;
}

// Ações do processo de assinatura: todas devolvem { message, data }.
async function action(documentId, name) {
  const response = await apiClient.post(`/documents/${documentId}/${name}`);

  return response.data;
}

export const sendDocument = (id) => action(id, 'send');
export const notifyDocument = (id) => action(id, 'notify');
export const syncDocument = (id) => action(id, 'sync');
export const cancelDocument = (id) => action(id, 'cancel');
export const discardFailedSend = (id) => action(id, 'discard-failed-send');

export async function downloadOriginalFile(id) {
  const response = await apiClient.get(`/documents/${id}/file`, { responseType: 'blob' });

  return response.data;
}

// PDF que vai para a Clicksign: o convertido (original Word) ou o próprio original (PDF).
export async function downloadSigningFile(id) {
  const response = await apiClient.get(`/documents/${id}/signing-file`, { responseType: 'blob' });

  return response.data;
}

// Nova tentativa de conversão Word → PDF de um rascunho. { message, data }.
export async function retryConversion(id) {
  const response = await apiClient.post(`/documents/${id}/convert`);

  return response.data;
}

export async function downloadSignedFile(id) {
  const response = await apiClient.get(`/documents/${id}/download-signed`, { responseType: 'blob' });

  return response.data;
}
