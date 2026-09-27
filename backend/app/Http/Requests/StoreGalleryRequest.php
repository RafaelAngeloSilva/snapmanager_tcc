<?php

namespace App\Http\Requests;

use App\Enums\WatermarkType;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Http\Requests\Concerns\NormalizesExpiration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGalleryRequest extends FormRequest
{
    use NormalizesExpiration;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'watermark_enabled' => ['sometimes', 'boolean'],
            'watermark_type' => ['sometimes', Rule::enum(WatermarkType::class)],
            'watermark_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expires_at.after' => 'A data de expiração da galeria não pode ser anterior à data atual.',
        ];
    }
}
