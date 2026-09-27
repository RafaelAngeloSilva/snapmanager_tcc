<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentSignerRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Signatários só mudam enquanto o documento é rascunho: a Clicksign não
 * permite alterar os dados de um signatário depois de adicioná-lo ao
 * envelope. As respostas devolvem o documento completo, para a tela de
 * detalhes se atualizar de uma vez.
 */
class DocumentSignerController extends Controller
{
    public function store(StoreDocumentSignerRequest $request, Document $document): JsonResponse
    {
        Gate::authorize('update', $document);

        if (! $document->status->isEditable()) {
            return $this->notEditable();
        }

        $signer = DocumentController::createSigner($document, $request->validated());
        $document->recordEvent(DocumentEventType::SignerAdded, "{$signer->name} adicionado como signatário ({$signer->channel->label()}).", user: $request->user());

        return DocumentResource::make($document->load(Document::DETAIL_RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * `$signer` vem por scoped binding (rota com scopeBindings): só resolve
     * se pertencer a `$document` — um id de signatário de outro documento dá 404.
     */
    public function destroy(Request $request, Document $document, DocumentSigner $signer): JsonResponse|DocumentResource
    {
        Gate::authorize('update', $document);

        if (! $document->status->isEditable()) {
            return $this->notEditable();
        }

        $signer->delete();
        $document->recordEvent(DocumentEventType::SignerRemoved, "{$signer->name} removido dos signatários.", user: $request->user());

        return DocumentResource::make($document->load(Document::DETAIL_RELATIONS));
    }

    private function notEditable(): JsonResponse
    {
        return response()->json(['message' => 'Só é possível alterar signatários enquanto o documento é rascunho.'], 409);
    }
}
