<?php

namespace App\Http\Requests;

use App\Support\FieldLimits;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Cadastro público fechado por padrão (config/auth.php:
        // registration_enabled). Fica no authorize() — e não no controller —
        // porque ele roda ANTES da validação: assim um cadastro fechado não
        // responde "e-mail já em uso" e não revela quem tem conta.
        return (bool) config('auth.registration_enabled');
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('O cadastro público está desativado. Peça a um administrador para criar sua conta.');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.FieldLimits::PERSON_NAME],
            'email' => ['required', 'string', 'email', 'max:'.FieldLimits::EMAIL, 'unique:users,email'],
            // "confirmed" exige um campo "password_confirmation" igual.
            // Password::defaults(): regra única (AppServiceProvider).
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
