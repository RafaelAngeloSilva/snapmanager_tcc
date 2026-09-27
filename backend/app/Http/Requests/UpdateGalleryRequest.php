<?php

namespace App\Http\Requests;

use App\Enums\WatermarkType;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Http\Requests\Concerns\NormalizesExpiration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGalleryRequest extends FormRequest
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
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
            'watermark_enabled' => ['sometimes', 'boolean'],
            'watermark_type' => ['sometimes', Rule::enum(WatermarkType::class)],
            'watermark_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * "not in the past" only applies when expires_at is actually being
     * changed to a new value — the form resends the current expires_at on
     * every edit, and a gallery that already expired naturally (a normal,
     * expected state) must stay editable for its other fields.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('expires_at') || $validator->errors()->has('expires_at')) {
                return;
            }

            $gallery = $this->route('gallery');
            $submitted = Carbon::parse($this->input('expires_at'));
            $current = $gallery?->expires_at;
            $isUnchanged = $current && $submitted->equalTo($current);

            if (! $isUnchanged && $submitted->lessThan(now())) {
                $validator->errors()->add('expires_at', 'A data de expiração da galeria não pode ser anterior à data atual.');
            }
        });
    }
}
