<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Sempre o próprio usuário autenticado (ver ProfileController) — não há
     * decisão de autorização aqui, só validação de dados.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Só "name" é aceito de propósito: e-mail e role são somente leitura
     * nesta tela (o usuário não pode promover o próprio perfil).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
