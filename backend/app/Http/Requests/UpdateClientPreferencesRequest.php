<?php

namespace App\Http\Requests;

use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->preference_notes)) {
            $trimmed = trim($this->preference_notes);
            $this->merge(['preference_notes' => $trimmed === '' ? null : $trimmed]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preference_notes' => ['present', 'nullable', 'string', 'max:'.FieldLimits::PREFERENCE_NOTES, 'not_regex:'.FieldLimits::NO_HTML_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preference_notes.max' => 'As notas de preferência podem ter no máximo :max caracteres.',
            'preference_notes.not_regex' => 'Não use marcações HTML (como <b> ou <script>) no texto.',
        ];
    }
}
