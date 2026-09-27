<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDocumentSigners;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentSignerRequest extends FormRequest
{
    use ValidatesDocumentSigners;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->signerRules();
    }

    public function messages(): array
    {
        return $this->signerMessages();
    }
}
