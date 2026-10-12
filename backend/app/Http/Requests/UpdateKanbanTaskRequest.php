<?php

namespace App\Http\Requests;

use App\Enums\KanbanPriority;
use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edição de card. `appointment_id` e `order_id` deliberadamente NÃO são
 * aceitos: um card que nasceu de um agendamento/pedido não pode ter esse
 * vínculo trocado depois — isso reescreveria a história da produção. Para
 * desfazer, remove-se o card e importa-se de novo.
 *
 * Mover entre colunas também não passa por aqui — é o endpoint /move, que
 * cuida do recálculo de posições.
 */
class UpdateKanbanTaskRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:'.FieldLimits::TITLE],
            'description' => ['nullable', 'string', 'max:'.FieldLimits::DESCRIPTION],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['sometimes', Rule::enum(KanbanPriority::class)],
            'due_date' => ['nullable', 'date', 'before:2100-01-01'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $this->route('task')?->client_id))],
            'gallery_id' => ['nullable', 'integer', Rule::exists('galleries', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $this->route('task')?->gallery_id))],
        ];
    }
}
