<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Support\FieldLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Auto-cadastro público. Retorna um token Sanctum direto (em vez de só
     * uma mensagem pedindo login) para manter a mesma experiência do login:
     * o usuário já entra autenticado, sem uma segunda etapa manual.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            // Cadastro público sempre entra com o perfil operacional menos
            // privilegiado; promoção de papel é uma ação administrativa,
            // fora do escopo desta tela (não há painel de gestão de usuários
            // ainda).
            'role' => UserRole::Attendant,
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->toAuthArray(),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:'.FieldLimits::EMAIL],
            // Sem o limite de 72 do cadastro: contas antigas podem ter
            // senha maior (a regra antiga não tinha máximo). O teto só evita
            // payloads absurdos.
            'password' => ['required', 'string', 'max:1024'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->toAuthArray(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }

    /**
     * Público: o front-end usa para mostrar ou esconder "Criar conta".
     */
    public function registrationStatus(): JsonResponse
    {
        return response()->json(['available' => (bool) config('auth.registration_enabled')]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->toAuthArray()
        );
    }

    /**
     * Sempre responde com a mesma mensagem genérica, exista ou não o e-mail
     * informado — evita que a resposta seja usada para descobrir quais
     * e-mails têm conta no sistema (enumeração de contas).
     */
    /**
     * Mensagem ÚNICA, com ou sem conta para o e-mail (não revela quem é
     * cliente do sistema).
     */
    public const FORGOT_PASSWORD_MESSAGE = 'Se existir uma conta associada a este e-mail, enviaremos as instruções para redefinição da senha.';

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');

        // O e-mail é gerado e enviado DEPOIS da resposta: a resposta sai no
        // mesmo tempo exista a conta ou não (sem isso, a demora do envio
        // denunciaria quais e-mails estão cadastrados). O password broker do
        // Laravel gera o token, guarda só o HASH dele e limita 1 envio por
        // minuto por e-mail (config/auth.php: throttle).
        dispatch(function () use ($email) {
            try {
                Password::sendResetLink(['email' => $email]);
            } catch (\Throwable $e) {
                // Falha do provedor de e-mail: registra sem expor o e-mail.
                Log::error('Falha ao enviar e-mail de redefinição de senha.', ['exception' => $e::class]);
            }
        })->afterResponse();

        return response()->json(['message' => self::FORGOT_PASSWORD_MESSAGE]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // Mesma regra da troca de senha: a nova precisa ser diferente
                // da atual. A exceção sai ANTES do broker apagar o token, então
                // o link continua valendo para tentar outra senha.
                if ($user->hasPassword() && Hash::check($password, $user->password)) {
                    throw ValidationException::withMessages([
                        'password' => ['A nova senha deve ser diferente da senha atual.'],
                    ]);
                }

                // O cast "password" => "hashed" do model já garante o hash.
                // Conta criada só pelo Google ganha aqui a primeira senha
                // (a posse do e-mail foi provada pelo link) e passa a entrar
                // das duas formas. Abrir o link também comprova o e-mail
                // (email_verified_at), usado no vínculo com o Google.
                $user->forceFill([
                    'password' => $password,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                // Quem redefine a senha pode estar recuperando uma conta
                // comprometida: encerra TODAS as sessões (tokens) existentes.
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // E-mail inexistente, token errado, expirado ou já usado: a
            // MESMA mensagem (não confirma se o e-mail tem conta).
            throw ValidationException::withMessages([
                'email' => ['Este link de redefinição é inválido ou expirou. Solicite um novo link.'],
            ]);
        }

        return response()->json(['message' => 'Senha redefinida com sucesso.']);
    }
}
