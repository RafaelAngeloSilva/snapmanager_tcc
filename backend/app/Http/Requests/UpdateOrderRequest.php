<?php

namespace App\Http\Requests;

use App\Rules\Money;
use App\Support\FieldLimits;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOrderRequest extends FormRequest
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
     * Note: "status" is intentionally not accepted here. Status changes must
     * go through PATCH /api/orders/{order}/status so every transition is
     * recorded in status_histories.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Cliente excluído não recebe NOVOS vínculos; manter o atual é permitido.
            'client_id' => ['sometimes', 'required', 'integer', Rule::exists('clients', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $this->route('order')?->client_id))],
            // O agendamento precisa ser do MESMO cliente do pedido (o novo, se
            // o cliente também está sendo trocado; senão, o atual).
            'appointment_id' => ['nullable', 'integer', Rule::exists('appointments', 'id')->where('client_id', (int) $this->input('client_id', $this->route('order')?->client_id))->withoutTrashed()],
            'title' => ['sometimes', 'required', 'string', 'max:'.FieldLimits::TITLE],
            'service_type' => ['nullable', 'string', 'max:'.FieldLimits::CATEGORY],
            'description' => ['nullable', 'string', 'max:'.FieldLimits::DESCRIPTION],
            'due_date' => ['nullable', 'date', 'before:2100-01-01'],
            'total' => ['sometimes', 'required', new Money],
            'notes' => ['nullable', 'string', 'max:'.FieldLimits::NOTES],
        ];
    }

    /**
     * "not in the past" only applies when due_date is actually being changed
     * to a new value — the front-end resends the current due_date on every
     * edit, and an order that's already overdue (a normal, expected state)
     * must stay editable for its other fields without being forced to move
     * its due date forward.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Trocar SÓ o cliente não pode deixar o pedido com o agendamento
            // de outro cliente (a regra de appointment_id só roda quando o
            // campo vem na requisição).
            $order = $this->route('order');

            if ($order && $this->filled('client_id') && ! $this->has('appointment_id') && ! $validator->errors()->has('client_id')
                && $order->appointment_id && (int) $this->input('client_id') !== (int) $order->client_id
                && optional($order->appointment()->first())->client_id !== (int) $this->input('client_id')) {
                $validator->errors()->add('appointment_id', 'O agendamento vinculado é de outro cliente. Remova ou troque o agendamento ao mudar o cliente.');
            }

            if (! $this->filled('due_date') || $validator->errors()->has('due_date')) {
                return;
            }

            $order = $this->route('order');
            $submitted = Carbon::parse($this->input('due_date'))->toDateString();
            $current = $order?->due_date?->toDateString();

            if ($submitted !== $current && $submitted < now()->toDateString()) {
                $validator->errors()->add('due_date', 'A data de entrega não pode ser anterior à data atual.');
            }
        });
    }
}
