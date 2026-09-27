<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDocumentFields;
use App\Http\Requests\Concerns\ValidatesDocumentSigners;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Novo documento (sempre nasce como rascunho). O status NÃO é aceito aqui:
 * quem define o estado de assinatura é só o back-end.
 */
class StoreDocumentRequest extends FormRequest
{
    use ValidatesDocumentFields, ValidatesDocumentSigners;

    public const MAX_SIGNERS = 10;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->documentRules((int) $this->input('client_id'), fileRequired: true),
            'signers' => ['nullable', 'array', 'max:'.self::MAX_SIGNERS],
            ...$this->signerRules('signers.*.'),
        ];
    }

    public function messages(): array
    {
        return [...$this->documentMessages(), ...$this->signerMessages('signers.*.')];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateFileContents($validator);
    }
}
