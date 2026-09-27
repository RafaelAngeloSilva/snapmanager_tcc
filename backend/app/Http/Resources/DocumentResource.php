<?php

namespace App\Http\Resources;

use App\Enums\DocumentConversionStatus;
use App\Enums\DocumentSignerStatus;
use App\Enums\DocumentStatus;
use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Documento — mesmo formato na listagem e no detalhe; o detalhe só traz a
 * mais os blocos carregados (signatários completos, histórico, criador).
 *
 * O que NUNCA sai daqui: caminho do arquivo no disco, Access Token, corpo
 * de respostas da Clicksign. Os ids da Clicksign aparecem só no bloco
 * `technical`, e só para admin.
 *
 * `abilities` diz ao front-end quais ações mostrar, já combinando PERMISSÃO
 * (Policy) e ESTADO do documento. É só conveniência de interface: cada
 * endpoint confere as duas coisas de novo no back-end.
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $status = $this->status;
        $isEditor = $user?->role === UserRole::Editor;

        $signers = $this->whenLoaded('signers');
        $signersLoaded = $this->relationLoaded('signers');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'type_label' => $this->type->label(),
            'status' => $status,
            'status_label' => $status->label(),
            'notes' => $this->notes,
            'expires_at' => $this->expires_at?->toDateString(),
            'sent_at' => $this->sent_at,
            'last_notified_at' => $this->last_notified_at,
            'signed_at' => $this->signed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // ORIGINAL: o arquivo enviado pelo funcionário (PDF ou Word).
            'file' => [
                'name' => $this->original_file_name,
                'size' => $this->original_file_size,
                'type' => $this->original_file_type,
                'type_label' => $this->original_file_type->label(),
            ],
            // PDF PARA ASSINATURA: o que vai para a Clicksign. Para original
            // PDF é o próprio original (`same_as_original`), sem duplicar.
            'signing_file' => [
                'same_as_original' => ! $this->original_file_type->needsConversion(),
                'ready' => $this->resource->isReadyForSigning(),
                'name' => $this->original_file_type->needsConversion()
                    ? pathinfo($this->original_file_name, PATHINFO_FILENAME).'.pdf'
                    : $this->original_file_name,
                'size' => $this->resource->signingFileSize(),
            ],
            'conversion' => $this->original_file_type->needsConversion() ? [
                'status' => $this->conversion_status,
                'label' => $this->conversion_status?->label() ?? 'Aguardando conversão',
            ] : null,
            'has_signed_file' => $status === DocumentStatus::Signed,

            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ] : null),
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'title' => $this->order->title,
            ] : null),
            // Editor não tem acesso à agenda no sistema: o vínculo não é exposto a ele.
            'appointment' => $this->when(! $isEditor, fn () => $this->whenLoaded('appointment', fn () => $this->appointment ? [
                'id' => $this->appointment->id,
                'title' => $this->appointment->title,
                'starts_at' => $this->appointment->starts_at,
            ] : null)),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),

            'signers_count' => $signersLoaded ? $this->signers->count() : null,
            'signed_count' => $signersLoaded ? $this->signers->where('status', DocumentSignerStatus::Signed)->count() : null,
            'channels' => $signersLoaded ? $this->signers->pluck('channel')->unique()->values() : null,
            'signers' => DocumentSignerResource::collection($signers),
            'events' => DocumentEventResource::collection($this->whenLoaded('events')),

            // Erro do último envio (mensagem já sanitizada), para quem pode agir sobre ele.
            'integration_error' => $this->when(
                $status === DocumentStatus::IntegrationError && $user?->can('operate', $this->resource),
                fn () => ['step' => $this->failed_step, 'message' => $this->last_error],
            ),

            'technical' => $this->when($user?->role === UserRole::Admin, fn () => [
                'clicksign_envelope_id' => $this->clicksign_envelope_id,
                'clicksign_document_id' => $this->clicksign_document_id,
            ]),

            'abilities' => $user ? $this->abilities($user) : [],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities($user): array
    {
        $status = $this->status;
        $canOperate = $user->can('operate', $this->resource);

        return [
            'update' => $status->isEditable() && $user->can('update', $this->resource),
            'manage_signers' => $status->isEditable() && $user->can('update', $this->resource),
            // Word sem PDF convertido não pode ser enviado.
            'send' => $status === DocumentStatus::Draft && $canOperate && $this->resource->isReadyForSigning(),
            'retry_conversion' => $status->isEditable()
                && $this->original_file_type->needsConversion()
                && $this->conversion_status !== DocumentConversionStatus::Converted
                && $user->can('update', $this->resource),
            'notify' => $status->isInProgress() && $canOperate,
            'sync' => $this->clicksign_document_id !== null && $canOperate,
            'discard_failed_send' => $status === DocumentStatus::IntegrationError && $canOperate,
            'cancel' => $status->isInProgress() && $user->can('cancel', $this->resource),
            'delete' => $status === DocumentStatus::Draft && $user->can('delete', $this->resource),
            'download_original' => $user->can('downloadFiles', $this->resource),
            'download_signing_pdf' => $this->resource->isReadyForSigning() && $user->can('downloadFiles', $this->resource),
            'download_signed' => $status === DocumentStatus::Signed && $user->can('downloadFiles', $this->resource),
        ];
    }
}
