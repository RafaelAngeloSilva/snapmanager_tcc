<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\UserRole;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            'user' => $user->only(['id', 'name', 'email', 'role', 'profile_photo_url']),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
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
            'user' => $user->only(['id', 'name', 'email', 'role', 'profile_photo_url']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->only(['id', 'name', 'email', 'role', 'profile_photo_url'])
        );
    }

    /**
     * Sempre responde com a mesma mensagem genérica, exista ou não o e-mail
     * informado — evita que a resposta seja usada para descobrir quais
     * e-mails têm conta no sistema (enumeração de contas).
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Se o e-mail informado estiver cadastrado, um link de redefinição de senha foi enviado.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // O cast "password" => "hashed" do model já garante o hash.
                $user->forceFill(['password' => $password])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => __($status)]);
    }
}
