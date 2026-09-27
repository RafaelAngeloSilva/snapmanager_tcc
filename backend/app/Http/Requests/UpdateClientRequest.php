<?php

namespace App\Http\Requests;

use App\Rules\ValidCpf;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @see StoreClientRequest::prepareForValidation()
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'zip_code' => $this->zip_code ? preg_replace('/\D/', '', $this->zip_code) : $this->zip_code,
            'document' => $this->document ? preg_replace('/\D/', '', $this->document) : $this->document,
            'state' => $this->state ? strtoupper($this->state) : $this->state,
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'document' => [
                'nullable', 'string', 'max:20', new ValidCpf,
                Rule::unique('clients', 'document')
                    ->ignore($this->route('client'))
                    ->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'zip_code' => ['nullable', 'digits:8'],
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
