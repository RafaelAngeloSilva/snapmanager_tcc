<?php

namespace App\Http\Requests;

use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateKanbanColumnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:'.FieldLimits::KANBAN_COLUMN],
            'is_done' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:'.FieldLimits::POSITION_MAX],
        ];
    }
}
