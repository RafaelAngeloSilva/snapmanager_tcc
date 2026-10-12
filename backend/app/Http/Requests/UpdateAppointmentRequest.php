<?php

namespace App\Http\Requests;

use App\Enums\AppointmentStatus;
use App\Http\Requests\Concerns\ChecksAppointmentSchedule;
use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAppointmentRequest extends FormRequest
{
    use ChecksAppointmentSchedule;

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
            // Cliente excluído não recebe NOVOS vínculos, mas manter o cliente
            // atual (já excluído) ao editar um agendamento antigo é permitido.
            'client_id' => ['sometimes', 'required', 'integer', Rule::exists('clients', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $this->route('appointment')?->client_id))],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['sometimes', 'required', 'string', 'max:'.FieldLimits::TITLE],
            'session_type' => ['nullable', 'string', 'max:'.FieldLimits::CATEGORY],
            'starts_at' => ['sometimes', 'required', 'date', 'before:2100-01-01'],
            'ends_at' => ['sometimes', 'required', 'date', 'before:2100-01-01'],
            'location' => ['nullable', 'string', 'max:'.FieldLimits::LOCATION],
            'status' => ['sometimes', Rule::enum(AppointmentStatus::class)],
            'notes' => ['nullable', 'string', 'max:'.FieldLimits::NOTES],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateAppointmentSchedule($validator));
    }
}
