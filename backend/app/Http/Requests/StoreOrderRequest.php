<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use App\Rules\Money;
use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "-0" no valor vira 0 antes de validar (ver App\Rules\Money).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('total')) {
            $this->merge(['total' => Money::normalize($this->input('total'))]);
        }
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
            // O agendamento precisa ser do MESMO cliente do pedido.
            'appointment_id' => ['nullable', 'integer', Rule::exists('appointments', 'id')->where('client_id', (int) $this->input('client_id'))->withoutTrashed()],
            'title' => ['required', 'string', 'max:'.FieldLimits::TITLE],
            'service_type' => ['nullable', 'string', 'max:'.FieldLimits::CATEGORY],
            'description' => ['nullable', 'string', 'max:'.FieldLimits::DESCRIPTION],
            'due_date' => ['nullable', 'date', 'after_or_equal:today', 'before:2100-01-01'],
            // "sometimes" (sem "nullable"): se omitido, o default da coluna
            // no banco (total=0, status=pending) é usado; se enviado, precisa
            // ser um valor válido.
            'total' => ['sometimes', 'required', new Money],
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'notes' => ['nullable', 'string', 'max:'.FieldLimits::NOTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => 'A data de entrega não pode ser anterior à data atual.',
        ];
    }
}
