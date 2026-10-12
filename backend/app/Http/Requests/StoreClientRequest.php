<?php

namespace App\Http\Requests;

use App\Rules\ValidCpf;
use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalizes zip_code/document to digits-only and state to uppercase
     * before validation, so masked input from the front-end (or a manually
     * typed value with punctuation) is handled consistently.
     */
    protected function prepareForValidation(): void
    {
        // Só normaliza STRINGS: um array no lugar de texto ("state[]=x")
        // segue intacto e é rejeitado pela regra "string" (em vez de
        // estourar um TypeError → 500).
        $digits = fn ($value) => is_string($value) && $value !== '' ? preg_replace('/\D/', '', $value) : $value;

        $this->merge([
            'zip_code' => $digits($this->input('zip_code')),
            'document' => $digits($this->input('document')),
            'state' => is_string($this->input('state')) ? mb_strtoupper($this->input('state')) : $this->input('state'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.FieldLimits::PERSON_NAME],
            'email' => ['nullable', 'string', 'email', 'max:'.FieldLimits::EMAIL],
            'phone' => ['nullable', 'string', 'max:'.FieldLimits::PHONE, 'regex:'.FieldLimits::PHONE_PATTERN],
            'document' => [
                'nullable', 'string', 'max:14', new ValidCpf,
                Rule::unique('clients', 'document')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'zip_code' => ['nullable', 'string', 'digits:8'],
            'street' => ['nullable', 'string', 'max:'.FieldLimits::STREET],
            'number' => ['nullable', 'string', 'max:'.FieldLimits::ADDRESS_NUMBER],
            'complement' => ['nullable', 'string', 'max:'.FieldLimits::COMPLEMENT],
            'neighborhood' => ['nullable', 'string', 'max:'.FieldLimits::NEIGHBORHOOD],
            'city' => ['nullable', 'string', 'max:'.FieldLimits::CITY],
            'state' => ['nullable', 'string', Rule::in(FieldLimits::STATES)],
            'notes' => ['nullable', 'string', 'max:'.FieldLimits::NOTES],
        ];
    }
}
