<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
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
     * Sem "exists:users,email" de propósito: validar a existência aqui
     * revelaria por status HTTP se um e-mail está cadastrado (enumeração de
     * contas). Quem decide isso é o password broker, que sempre responde de
     * forma genérica independente do resultado — ver AuthController::forgotPassword.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }
}
