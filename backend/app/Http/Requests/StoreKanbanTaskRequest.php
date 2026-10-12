<?php

namespace App\Http\Requests;

use App\Enums\KanbanPriority;
use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criação de demanda avulsa no quadro. Vínculos com agendamento/pedido NÃO
 * entram por aqui: eles têm endpoints próprios
 * (POST /appointments/{id}/kanban e POST /orders/{id}/kanban), que copiam
 * os dados do registro de origem e garantem a não duplicação.
 */
class StoreKanbanTaskRequest extends FormRequest
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
            'kanban_column_id' => ['required', 'integer', 'exists:kanban_columns,id'],
            'title' => ['required', 'string', 'max:'.FieldLimits::TITLE],
            'description' => ['nullable', 'string', 'max:'.FieldLimits::DESCRIPTION],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['sometimes', Rule::enum(KanbanPriority::class)],
            'due_date' => ['nullable', 'date', 'before:2100-01-01'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->withoutTrashed()],
            'gallery_id' => ['nullable', 'integer', Rule::exists('galleries', 'id')->withoutTrashed()],
        ];
    }
}
