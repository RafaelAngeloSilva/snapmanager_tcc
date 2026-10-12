<?php

namespace App\Http\Requests;

use App\Enums\WatermarkType;
use App\Http\Requests\Concerns\NormalizesExpiration;
use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
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
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->withoutTrashed()],
            // O pedido precisa ser do MESMO cliente da galeria.
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->where('client_id', (int) $this->input('client_id'))->withoutTrashed()],
            'title' => ['required', 'string', 'max:'.FieldLimits::TITLE],
            'description' => ['nullable', 'string', 'max:'.FieldLimits::DESCRIPTION],
            'expires_at' => ['nullable', 'date', 'after:now', 'before:2100-01-01'],
            'watermark_enabled' => ['sometimes', 'boolean'],
            'watermark_type' => ['sometimes', Rule::enum(WatermarkType::class)],
            'watermark_text' => ['nullable', 'string', 'max:'.FieldLimits::WATERMARK_TEXT],
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
