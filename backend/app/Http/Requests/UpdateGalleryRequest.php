<?php

namespace App\Http\Requests;

use App\Enums\WatermarkType;
use App\Http\Requests\Concerns\NormalizesExpiration;
use App\Support\FieldLimits;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
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
            // Cliente excluído não recebe NOVOS vínculos; manter o atual é permitido.
            'client_id' => ['sometimes', 'required', 'integer', Rule::exists('clients', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $this->route('gallery')?->client_id))],
            // O pedido precisa ser do MESMO cliente da galeria.
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->where('client_id', (int) $this->input('client_id', $this->route('gallery')?->client_id))->withoutTrashed()],
            'title' => ['sometimes', 'required', 'string', 'max:'.FieldLimits::TITLE],
            'description' => ['nullable', 'string', 'max:'.FieldLimits::DESCRIPTION],
            'expires_at' => ['nullable', 'date', 'before:2100-01-01'],
            'watermark_enabled' => ['sometimes', 'boolean'],
            'watermark_type' => ['sometimes', Rule::enum(WatermarkType::class)],
            'watermark_text' => ['nullable', 'string', 'max:'.FieldLimits::WATERMARK_TEXT],
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
            // Trocar SÓ o cliente não pode deixar a galeria com o pedido de
            // outro cliente.
            $gallery = $this->route('gallery');

            if ($gallery && $this->filled('client_id') && ! $this->has('order_id') && ! $validator->errors()->has('client_id')
                && $gallery->order_id && (int) $this->input('client_id') !== (int) $gallery->client_id
                && optional($gallery->order()->first())->client_id !== (int) $this->input('client_id')) {
                $validator->errors()->add('order_id', 'O pedido vinculado é de outro cliente. Remova ou troque o pedido ao mudar o cliente.');
            }

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
