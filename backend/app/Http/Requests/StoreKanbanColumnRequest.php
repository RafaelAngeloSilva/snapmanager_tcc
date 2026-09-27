<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreKanbanColumnRequest extends FormRequest
{
    /**
     * Autorização fica no middleware `role:admin` da rota (gestão do quadro
     * é administrativa) — aqui só validação.
     */
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
            'name' => ['required', 'string', 'max:60'],
            'is_done' => ['sometimes', 'boolean'],
        ];
    }
}
