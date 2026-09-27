<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /**
     * Consultado pelo front-end para decidir se mostra o botão "Entrar com
     * Google" — sem isso, o botão levaria a um redirect quebrado sempre que
     * as credenciais não estiverem configuradas (ambiente local sem chaves).
     */
    public function status(): JsonResponse
    {
        return response()->json(['available' => $this->isConfigured()]);
    }

    /**
     * GET /auth/google/redirect (routes/web.php, não api.php: precisa do
     * middleware de sessão do grupo "web" para o Socialite guardar e validar
     * o parâmetro "state" via CSRF, protegendo contra state injection).
     */
    public function redirect(): RedirectResponse
    {
        abort_unless($this->isConfigured(), 503, 'Login com Google não está configurado neste ambiente.');

        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        abort_unless($this->isConfigured(), 503, 'Login com Google não está configurado neste ambiente.');

        try {
            // Modo stateful (default): Socialite compara o "state" devolvido
            // pelo Google com o que foi salvo na sessão em redirect() acima,
            // rejeitando a troca se não bater.
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            Log::warning('Falha na autenticação com Google', ['message' => $e->getMessage()]);

            return redirect("{$frontendUrl}/login?google_error=1");
        }

        // Encontrado ou criado por e-mail, com o mesmo papel padrão do
        // auto-cadastro comum (attendant). Senha aleatória: essa conta só
        // pode ser acessada via Google, nunca por login com senha.
        $user = User::firstOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: $googleUser->getEmail(),
                'password' => Hash::make(Str::random(40)),
                'role' => 'attendant',
            ]
        );

        $token = $user->createToken('api-token')->plainTextToken;

        return redirect("{$frontendUrl}/login/google/callback?token={$token}");
    }
}
