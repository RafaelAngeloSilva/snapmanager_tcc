<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderKanbanColumnsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Recebe a lista de ids na ordem desejada; o controller grava
     * position = índice do array.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'columns' => ['required', 'array', 'min:1', 'max:100'],
            'columns.*' => ['required', 'integer', 'distinct', 'exists:kanban_columns,id'],
        ];
    }
}
