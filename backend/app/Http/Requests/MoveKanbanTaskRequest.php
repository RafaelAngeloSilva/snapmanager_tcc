<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MoveKanbanTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * position é a posição de destino dentro da coluna (0-indexada). O
     * controller normaliza as posições das colunas afetadas depois do
     * movimento, então um valor maior que o tamanho da coluna apenas joga o
     * card para o fim — não é erro.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kanban_column_id' => ['required', 'integer', 'exists:kanban_columns,id'],
            'position' => ['required', 'integer', 'min:0'],
        ];
    }
}
