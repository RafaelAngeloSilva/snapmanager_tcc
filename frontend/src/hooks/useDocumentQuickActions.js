import { useCallback, useState } from 'react';
import { syncDocument } from '../api/documents';
import { apiErrorMessage, downloadErrorMessage, saveDocumentFile } from '../utils/documents';

/**
 * Ações rápidas sobre documentos em LISTAS (tabela da aba Documentos e
 * seção "Documentos vinculados" de Pedidos/Agenda): sincronizar status e
 * baixar original/assinado.
 *
 * Um único estado `busy` para a lista inteira: enquanto uma ação roda,
 * nenhuma outra dispara (sem duplo clique, sem requisições em paralelo para
 * a mesma operação). Usa os mesmos endpoints do modal de detalhes — o React
 * nunca fala com a Clicksign.
 *
 * @param {{ onUpdated: (document) => void, onNotice: (notice|null) => void }} callbacks
 */
export default function useDocumentQuickActions({ onUpdated, onNotice }) {
  const [busy, setBusy] = useState(null); // { id, action }

  const isBusy = useCallback((document, action) => busy?.id === document.id && busy?.action === action, [busy]);

  async function sync(document) {
    if (busy) return;

    setBusy({ id: document.id, action: 'sync' });
    onNotice(null);

    try {
      const result = await syncDocument(document.id);
      onUpdated(result.data);
      onNotice({ tone: 'success', message: `${document.title}: ${result.message}` });
    } catch (err) {
      // Em falha da Clicksign o back-end manda o estado atual do documento.
      if (err.response?.data?.data) onUpdated(err.response.data.data);
      onNotice({ tone: 'error', message: apiErrorMessage(err) });
    } finally {
      setBusy(null);
    }
  }

  async function download(document, kind) {
    if (busy) return;

    setBusy({ id: document.id, action: kind });
    onNotice(null);

    try {
      await saveDocumentFile(document, kind);
    } catch (err) {
      onNotice({ tone: 'error', message: await downloadErrorMessage(err, 'Não foi possível baixar o arquivo.') });
    } finally {
      setBusy(null);
    }
  }

  return { busy: Boolean(busy), isBusy, sync, download };
}
