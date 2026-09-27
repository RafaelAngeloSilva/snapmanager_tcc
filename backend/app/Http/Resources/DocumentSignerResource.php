<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * E-mail e telefone só saem para quem gerencia documentos (admin e
 * atendimento). Fotógrafo e editor veem nome, canal e status — o contato
 * pessoal do signatário não é necessário para acompanhar o andamento.
 */
class DocumentSignerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $showContacts = (bool) $request->user()?->can('viewSignerContacts', Document::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel,
            'channel_label' => $this->channel->label(),
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'signed_at' => $this->signed_at,
            'email' => $this->when($showContacts, $this->email),
            'phone_number' => $this->when($showContacts, $this->phone_number),
        ];
    }
}
