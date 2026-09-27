<?php

namespace App\Http\Controllers\Api;

use App\Enums\ClicksignErrorReason;
use App\Exceptions\ClicksignException;
use App\Exceptions\DocumentActionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\DocumentSignatureService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ações do processo de assinatura. Cada uma: autoriza (Policy, por
 * registro) → delega ao DocumentSignatureService → devolve o documento
 * atualizado. Falhas viram JSON com mensagem amigável:
 *  - DocumentActionException → 409/422/429 (estado não permite a ação);
 *  - ClicksignException → 502 (503 se a integração não estiver configurada),
 *    com a mensagem já sanitizada — nunca token nem corpo bruto.
 */
class DocumentSignatureController extends Controller
{
    public function __construct(private readonly DocumentSignatureService $signatures) {}

    public function send(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('operate', $document);

        return $this->run($document, fn () => $this->signatures->send($document, $request->user()),
            'Documento enviado à Clicksign. Agora envie a solicitação de assinatura aos signatários.');
    }

    public function notify(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('operate', $document);

        return $this->run($document, fn () => $this->signatures->notify($document, $request->user()),
            'Solicitação de assinatura enviada.');
    }

    public function sync(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('operate', $document);

        $changed = false;

        return $this->run($document, function () use ($document, $request, &$changed) {
            $result = $this->signatures->sync($document, $request->user());
            $changed = $result['changed'];

            return $result['document'];
        }, fn () => $changed ? 'Status atualizado a partir da Clicksign.' : 'Tudo em dia: nenhuma alteração na Clicksign.');
    }

    public function cancel(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('cancel', $document);

        return $this->run($document, fn () => $this->signatures->cancel($document, $request->user()),
            'Processo de assinatura cancelado.');
    }

    public function discardFailedSend(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('operate', $document);

        return $this->run($document, fn () => $this->signatures->discardFailedSend($document, $request->user()),
            'Envio descartado. O documento voltou a ser rascunho e pode ser enviado novamente.');
    }

    /**
     * React → Laravel → Clicksign → Laravel → usuário. O navegador nunca
     * recebe o link temporário da Clicksign nem o Access Token: o arquivo
     * é baixado pelo servidor (e guardado em cópia local) e servido daqui.
     */
    public function downloadSigned(Document $document): Response
    {
        Gate::authorize('downloadFiles', $document);

        try {
            $path = $this->signatures->signedFilePath($document);
        } catch (DocumentActionException|ClicksignException $e) {
            return $this->failure($e);
        }

        return Storage::disk(DocumentSignatureService::DISK)->response(
            $path,
            DocumentController::downloadName($document, 'assinado'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * @param  Closure(): Document  $action
     * @param  string|Closure(): string  $successMessage
     */
    private function run(Document $document, Closure $action, string|Closure $successMessage): JsonResponse
    {
        try {
            $updated = $action();
        } catch (DocumentActionException|ClicksignException $e) {
            return $this->failure($e, $document);
        }

        return response()->json([
            'message' => $successMessage instanceof Closure ? $successMessage() : $successMessage,
            'data' => DocumentResource::make($updated->load(Document::DETAIL_RELATIONS)),
        ]);
    }

    private function failure(DocumentActionException|ClicksignException $e, ?Document $document = null): JsonResponse
    {
        if ($e instanceof DocumentActionException) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $status = $e->reason === ClicksignErrorReason::NotConfigured ? 503 : 502;

        return response()->json([
            'message' => 'A Clicksign não concluiu a operação: '.$e->getMessage(),
            'reason' => $e->reason->value,
            // O documento pode ter mudado (ex.: ficou em integration_error):
            // a tela recebe o estado atual para mostrar o que aconteceu.
            'data' => $document ? DocumentResource::make($document->refresh()->load(Document::DETAIL_RELATIONS)) : null,
        ], $status);
    }
}
