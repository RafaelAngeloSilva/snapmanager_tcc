<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
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
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'service_type' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
            'total' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
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
