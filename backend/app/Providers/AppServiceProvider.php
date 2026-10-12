<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use App\Models\PersonalAccessToken;
use App\Support\FieldLimits;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Evita um UPDATE no banco a cada requisição só para gravar a hora de
        // uso do token (ver App\Models\PersonalAccessToken).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Regra de senha ÚNICA para cadastro, redefinição e troca de senha
        // (Password::defaults() nos Form Requests). Máximo de 72 caracteres:
        // o bcrypt considera só os primeiros 72 BYTES, então uma senha maior
        // daria uma falsa sensação de segurança (com acentos, que ocupam 2
        // bytes, o corte do bcrypt chega um pouco antes).
        Password::defaults(fn () => Password::min(FieldLimits::PASSWORD_MIN)->max(FieldLimits::PASSWORD_MAX));

        $this->configureRateLimiting();

        // MAIL_MAILER=brevo → API HTTPS da Brevo (sem pacote extra).
        Mail::extend('brevo', fn (array $config) => new BrevoTransport($config['key'] ?? null, (int) ($config['timeout'] ?? 10)));

        // Com APP_URL em https (produção), toda URL absoluta gerada pelo
        // Laravel (ex.: thumbnail_url da galeria pública, foto de perfil)
        // sai em https — mesmo que o proxy da hospedagem não repasse o
        // protocolo. Em desenvolvimento (APP_URL http) nada muda.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // A app é uma SPA separada do backend (não há rota web "password.reset"
        // aqui), então o link do e-mail/notificação precisa apontar pro
        // front-end React em vez do default do Laravel.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = rtrim(config('app.frontend_url'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($user->email);
        });
    }

    /**
     * Limites de requisições por rota sensível (usados como
     * ->middleware('throttle:<nome>') em routes/api.php). Resposta 429 com
     * mensagem em português; o cache padrão guarda os contadores.
     */
    private function configureRateLimiting(): void
    {
        $tooMany = fn (string $message) => fn (Request $request, array $headers) => response()->json(['message' => $message], 429, $headers);
        $wait = 'Muitas tentativas. Aguarde um minuto e tente novamente.';

        // Login: por e-mail+IP (adivinhar a senha de UMA conta) e por IP
        // (testar muitas contas a partir do mesmo lugar).
        // O limitador roda ANTES da validação: o e-mail pode vir como array.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by((is_string($request->input('email')) ? mb_strtolower($request->input('email')) : '').'|'.$request->ip())->response($tooMany($wait)),
            Limit::perMinute(20)->by('login-ip|'.$request->ip())->response($tooMany($wait)),
        ]);

        // Login com Google (ida, volta e troca do código): por IP.
        RateLimiter::for('oauth', fn (Request $request) => Limit::perMinute(20)->by('oauth|'.$request->ip())->response($tooMany($wait)));

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip())
            ->response($tooMany('Muitos cadastros a partir desta rede. Tente novamente mais tarde.')));

        // Esqueci a senha / redefinir: evita envio em massa de e-mails e
        // tentativa de adivinhar tokens.
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by($request->ip())->response($tooMany($wait)));

        // Troca de senha logado: protege a verificação da senha atual.
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by('user|'.$request->user()?->id)->response($tooMany($wait)));

        // Uploads (fotos, contratos, foto de perfil, marca d'água). O front
        // envia UMA foto por requisição: 120/min cobre uma galeria grande.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(120)->by('user|'.$request->user()?->id)
            ->response($tooMany('Muitos envios em pouco tempo. Aguarde um instante e tente novamente.')));

        // Galeria pública (cliente, sem login): leitura generosa (cada
        // miniatura é uma requisição) e escrita (seleção) restrita.
        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(600)->by($request->ip())->response($tooMany($wait)));
        RateLimiter::for('public-write', fn (Request $request) => Limit::perMinute(20)->by($request->ip())->response($tooMany($wait)));
    }
}
