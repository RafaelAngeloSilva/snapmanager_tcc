<?php

namespace App\Http\Requests;

use App\Models\KanbanColumn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Excluir coluna nunca apaga tarefas silenciosamente: se a coluna tem
 * cards, o cliente da API precisa informar explicitamente para onde eles
 * vão (`move_tasks_to`). O controller faz a movimentação numa transação
 * antes de remover a coluna.
 */
class DestroyKanbanColumnRequest extends FormRequest
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
            'move_tasks_to' => ['nullable', 'integer', 'exists:kanban_columns,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var KanbanColumn|null $column */
            $column = $this->route('column');

            if (! $column) {
                return;
            }

            $destination = $this->input('move_tasks_to');

            if ($destination && (int) $destination === $column->id) {
                $validator->errors()->add('move_tasks_to', 'A coluna de destino precisa ser diferente da coluna excluída.');

                return;
            }

            if (! $destination && $column->tasks()->exists()) {
                $validator->errors()->add(
                    'move_tasks_to',
                    'Esta coluna possui tarefas. Informe para qual coluna elas devem ser movidas antes de excluí-la.'
                );
            }
        });
    }
}
