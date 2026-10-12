<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\GoogleLoginException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * Login com Google (Laravel Socialite, OAuth 2.0 / OpenID Connect).
 *
 *   React                         Laravel (routes/web.php — com sessão)       Google
 *   ─────                         ──────────────────────────────────────      ──────
 *   gera nonce, guarda na aba ──► GET /auth/google/redirect?nonce=…      ──► tela de login
 *                                 guarda nonce na sessão + state (Socialite)
 *                                 GET /auth/google/callback?code&state  ◄── autoriza / cancela
 *                                 valida state, busca/vincula/cria usuário,
 *                                 gera CÓDIGO de uso único (60 s)
 *   /login/google/callback#code ◄─ redirect para o FRONTEND_URL
 *   POST /api/auth/google/exchange {code, nonce} ──► confere código + nonce → token Sanctum
 *
 * Por que o código e o nonce:
 *  - o token de acesso NUNCA aparece numa URL (histórico, logs, Referer);
 *    o código vai no fragmento (#), que o navegador não envia a servidores;
 *  - o código só vale com o nonce que ficou guardado NA MESMA ABA que
 *    iniciou o login: um link com o código de outra pessoa não loga a
 *    vítima na conta do atacante (login CSRF).
 *
 * Redirects: o destino é sempre FRONTEND_URL + caminho fixo. Nenhum
 * parâmetro do usuário escolhe para onde voltar (sem open redirect).
 */
class GoogleAuthController extends Controller
{
    private const SESSION_NONCE = 'google_oauth_nonce';

    private const CODE_TTL_SECONDS = 60;

    /** base64url de 32 bytes = 43 caracteres. */
    private const NONCE_PATTERN = '/^[A-Za-z0-9_-]{32,128}$/';

    public function __construct(private readonly GoogleAccountService $accounts) {}

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    /**
     * Consultado pelo front-end para decidir se mostra o botão "Continuar
     * com Google".
     */
    public function status(): JsonResponse
    {
        return response()->json(['available' => $this->isConfigured()]);
    }

    /**
     * GET /auth/google/redirect?nonce=... (routes/web.php: precisa da sessão
     * para o Socialite guardar e conferir o "state").
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return $this->backToLogin('failed');
        }

        $nonce = $request->query('nonce');

        if (! is_string($nonce) || ! preg_match(self::NONCE_PATTERN, $nonce)) {
            return $this->backToLogin('invalid_request');
        }

        $request->session()->put(self::SESSION_NONCE, hash('sha256', $nonce));

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    /**
     * GET /auth/google/callback — o Google volta aqui.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return $this->backToLogin('failed');
        }

        // Nonce de uso único: sai da sessão já na primeira volta.
        $nonceHash = $request->session()->pull(self::SESSION_NONCE);

        try {
            // Usuário clicou em "Cancelar" (ou o Google recusou): o Google
            // devolve ?error=access_denied (ou outro código), sem "code".
            if ($request->filled('error')) {
                throw new GoogleLoginException($request->query('error') === 'access_denied' ? 'cancelled' : 'failed');
            }

            if (! is_string($nonceHash) || $nonceHash === '') {
                throw new GoogleLoginException('expired');
            }

            try {
                // Modo stateful: o Socialite compara o "state" devolvido pelo
                // Google com o guardado na sessão em redirect() — se não
                // bater, lança InvalidStateException.
                $googleUser = Socialite::driver('google')->user();
            } catch (InvalidStateException) {
                throw new GoogleLoginException('invalid_state');
            } catch (Throwable $e) {
                Log::warning('Falha ao obter os dados do Google.', ['exception' => $e::class]);

                throw new GoogleLoginException('failed');
            }

            $user = $this->accounts->resolveUser($googleUser);
        } catch (GoogleLoginException $e) {
            Log::info('Login com Google não concluído.', ['reason' => $e->reason]);

            return $this->backToLogin($e->reason);
        }

        $code = Str::random(64);

        Cache::put($this->cacheKey($code), [
            'user_id' => $user->id,
            'nonce_hash' => $nonceHash,
        ], self::CODE_TTL_SECONDS);

        // Fragmento (#): não vai para o servidor da Vercel nem para logs.
        return redirect()->away($this->frontendUrl().'/login/google/callback#code='.$code);
    }

    /**
     * POST /api/auth/google/exchange {code, nonce} → token de acesso.
     */
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:64', 'alpha_num'],
            'nonce' => ['required', 'string', 'regex:'.self::NONCE_PATTERN],
        ]);

        // Uso único: o código sai do cache na primeira tentativa, válido ou não.
        $entry = Cache::pull($this->cacheKey($data['code']));

        $valid = is_array($entry)
            && hash_equals((string) ($entry['nonce_hash'] ?? ''), hash('sha256', $data['nonce']));

        $user = $valid ? User::find($entry['user_id']) : null;

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => ['O login com Google expirou ou é inválido. Tente novamente.'],
            ]);
        }

        return response()->json([
            'token' => $user->createToken('api-token')->plainTextToken,
            'user' => $user->toAuthArray(),
        ]);
    }

    private function cacheKey(string $code): string
    {
        return 'google-login:'.hash('sha256', $code);
    }

    private function frontendUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }

    private function backToLogin(string $reason): RedirectResponse
    {
        $reason = in_array($reason, GoogleLoginException::REASONS, true) ? $reason : 'failed';

        return redirect()->away($this->frontendUrl().'/login?google_error='.$reason);
    }
}
