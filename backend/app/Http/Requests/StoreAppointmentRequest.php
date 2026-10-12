<?php

namespace App\Http\Requests;

use App\Enums\AppointmentStatus;
use App\Http\Requests\Concerns\ChecksAppointmentSchedule;
use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAppointmentRequest extends FormRequest
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
     * Default the status to "scheduled" when omitted, instead of letting the
     * nullable rule pass an explicit null through to validated()/create().
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('status')) {
            $this->merge(['status' => 'scheduled']);
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
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:'.FieldLimits::TITLE],
            'session_type' => ['nullable', 'string', 'max:'.FieldLimits::CATEGORY],
            'starts_at' => ['required', 'date', 'before:2100-01-01'],
            'ends_at' => ['required', 'date', 'before:2100-01-01'],
            'location' => ['nullable', 'string', 'max:'.FieldLimits::LOCATION],
            'status' => ['nullable', Rule::enum(AppointmentStatus::class)],
            'notes' => ['nullable', 'string', 'max:'.FieldLimits::NOTES],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateAppointmentSchedule($validator));
    }
}
