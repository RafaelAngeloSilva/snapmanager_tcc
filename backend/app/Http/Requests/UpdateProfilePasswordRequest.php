<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateProfilePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "current_password" é uma regra nativa do Laravel: valida contra a
     * senha do usuário autenticado no guard atual — não precisamos comparar
     * hash manualmente.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Conta criada só pelo Google não tem senha atual para confirmar —
        // a mensagem própria sai em withValidator().
        if (! $this->user()?->hasPassword()) {
            return ['password' => ['nullable']];
        }

        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * A nova senha precisa ser DIFERENTE da atual. A comparação é feita
     * contra o HASH guardado (Hash::check), não contra o campo
     * current_password enviado — a regra vale mesmo que o cliente da API
     * mande os dois campos com valores diferentes do que digitou.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Definir a PRIMEIRA senha exige provar a posse do e-mail (link
            // do "Esqueci minha senha"), não só estar logado: uma sessão
            // roubada não pode criar uma senha permanente para a conta.
            if (! $this->user()?->hasPassword()) {
                $validator->errors()->add('current_password', 'Sua conta entra com o Google e ainda não tem senha. Para criar uma, use "Esqueci minha senha" na tela de login.');

                return;
            }

            if ($validator->errors()->hasAny(['current_password', 'password'])) {
                return;
            }

            $user = $this->user();

            if ($user && Hash::check((string) $this->input('password'), $user->password)) {
                $validator->errors()->add('password', 'A nova senha deve ser diferente da senha atual.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Senha atual incorreta.',
            'password.confirmed' => 'As senhas não coincidem.',
        ];
    }
}
