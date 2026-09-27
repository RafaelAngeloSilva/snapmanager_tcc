<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDocumentFields;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Edição de rascunho. O arquivo é opcional (só substitui se vier outro).
 * Enviado como POST multipart com `_method=PUT` — PHP não lê arquivos em
 * requisições PUT de verdade.
 */
class UpdateDocumentRequest extends FormRequest
{
    use ValidatesDocumentFields;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Document $document */
        $document = $this->route('document');

        // Se o cliente não mudar, pedido/agendamento são conferidos contra o atual.
        return $this->documentRules((int) $this->input('client_id', $document->client_id), fileRequired: false);
    }

    public function messages(): array
    {
        return $this->documentMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateFileContents($validator);
    }
}
