<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Mail\Transport\BrevoTransport;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Autenticação: login tradicional, login com Google (Socialite simulado —
 * nenhuma chamada real ao Google), recuperação/redefinição de senha e
 * envio de e-mail pela API da Brevo (HTTP simulado).
 */
class AuthenticationFlowsTest extends TestCase
{
    use RefreshDatabase;

    private const NONCE = 'nonce-de-teste-com-mais-de-trinta-e-dois-caracteres_123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'id-falso.apps.googleusercontent.com',
            'services.google.client_secret' => 'segredo-falso',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
            'app.frontend_url' => 'https://snapmanager.vercel.app',
        ]);
    }

    // ================================================== login tradicional

    public function test_traditional_login_valid_wrong_password_and_unknown_user(): void
    {
        User::factory()->create(['email' => 'ana@estudio.test', 'password' => 'SenhaCerta123']);

        $this->postJson('/api/login', ['email' => 'ana@estudio.test', 'password' => 'SenhaCerta123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'has_password', 'google_linked']])
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.google_id');

        // Senha errada e usuário inexistente: MESMA resposta (sem enumeração).
        $wrong = $this->postJson('/api/login', ['email' => 'ana@estudio.test', 'password' => 'errada'])->assertStatus(422)->json('errors');
        $unknown = $this->postJson('/api/login', ['email' => 'ninguem@estudio.test', 'password' => 'errada'])->assertStatus(422)->json('errors');
        $this->assertSame($wrong, $unknown);
    }

    public function test_google_only_account_cannot_log_in_with_a_password(): void
    {
        $user = User::factory()->create(['email' => 'g@estudio.test']);
        $user->forceFill(['password' => null, 'google_id' => 'google-1'])->save();

        $this->postJson('/api/login', ['email' => 'g@estudio.test', 'password' => ''])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'g@estudio.test', 'password' => 'qualquer-coisa'])->assertStatus(422);
    }

    public function test_protected_routes_require_authentication(): void
    {
        foreach (['/api/me', '/api/profile', '/api/clients', '/api/orders'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    // ========================================================= Google

    private function googleUser(array $overrides = []): SocialiteUser
    {
        $data = array_merge([
            'sub' => '1098765432101234567890',
            'email' => 'maria@gmail.com',
            'email_verified' => true,
            'name' => 'Maria Fotógrafa',
            'picture' => null,
        ], $overrides);

        return (new SocialiteUser)->setRaw($data)->map([
            'id' => $data['sub'],
            'name' => $data['name'],
            'email' => $data['email'],
            'avatar' => $data['picture'],
        ]);
    }

    private function mockGoogle(SocialiteUser|\Throwable $result): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?state=xyz'));

        if ($result instanceof \Throwable) {
            $provider->shouldReceive('user')->andThrow($result);
        } else {
            $provider->shouldReceive('user')->andReturn($result);
        }

        // swap(): cada chamada substitui o mock anterior por completo.
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($provider);
        Socialite::swap($factory);
    }

    /** Simula a volta do Google para o callback (com o nonce na sessão). */
    private function googleCallback(array $query = ['code' => 'codigo-do-google', 'state' => 'xyz'], ?string $nonce = self::NONCE, string $intent = 'login')
    {
        $session = $nonce === null ? [] : ['google_oauth_nonce' => hash('sha256', $nonce), 'google_oauth_intent' => $intent];

        return $this->withSession($session)->get('/auth/google/callback?'.http_build_query($query));
    }

    private function codeFrom($response): string
    {
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://snapmanager.vercel.app/login/google/callback#code=', $location);
        $this->assertStringNotContainsString('token', $location, 'O token de acesso nunca vai na URL.');

        return substr($location, strpos($location, '#code=') + 6);
    }

    private function signupErrorFrom($response): string
    {
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://snapmanager.vercel.app/register?google_error=', $location);

        return substr($location, strlen('https://snapmanager.vercel.app/register?google_error='));
    }

    private function signupCallback()
    {
        return $this->googleCallback(intent: 'signup');
    }

    private function loginErrorFrom($response): string
    {
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://snapmanager.vercel.app/login?google_error=', $location);

        return substr($location, strlen('https://snapmanager.vercel.app/login?google_error='));
    }

    public function test_google_redirect_requires_a_nonce_and_never_uses_a_caller_supplied_destination(): void
    {
        $this->mockGoogle($this->googleUser());

        $this->assertSame('invalid_request', $this->loginErrorFrom($this->get('/auth/google/redirect')));
        $this->assertSame('invalid_request', $this->loginErrorFrom($this->get('/auth/google/redirect?nonce=curto')));

        $response = $this->get('/auth/google/redirect?nonce='.self::NONCE.'&redirect=https://site-malicioso.example&return_to=//evil.example');
        $this->assertStringStartsWith('https://accounts.google.com/', $response->headers->get('Location'));
        $response->assertSessionHas('google_oauth_nonce', hash('sha256', self::NONCE));
    }

    public function test_new_google_account_is_created_without_password_when_registration_is_open(): void
    {
        config(['auth.registration_enabled' => true]);
        $this->mockGoogle($this->googleUser());

        $code = $this->codeFrom($this->signupCallback());

        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => self::NONCE])
            ->assertOk()
            ->assertJsonPath('user.email', 'maria@gmail.com')
            ->assertJsonPath('user.role', 'attendant')
            ->assertJsonPath('user.has_password', false)
            ->assertJsonPath('user.google_linked', true)
            ->assertJsonStructure(['token']);

        $user = User::where('email', 'maria@gmail.com')->sole();
        $this->assertSame('1098765432101234567890', $user->google_id);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_new_google_account_is_refused_when_registration_is_closed(): void
    {
        config(['auth.registration_enabled' => false]);
        $this->mockGoogle($this->googleUser());

        $this->assertSame('registration_closed', $this->signupErrorFrom($this->signupCallback()));
        $this->assertDatabaseMissing('users', ['email' => 'maria@gmail.com']);
    }

    public function test_login_with_google_never_creates_an_account_nor_issues_a_token(): void
    {
        config(['auth.registration_enabled' => true]);   // mesmo com o cadastro aberto
        $this->mockGoogle($this->googleUser());

        $response = $this->googleCallback();

        $this->assertSame('no_account', $this->loginErrorFrom($response));
        $this->assertStringNotContainsString('#code=', (string) $response->headers->get('Location'));
        $this->assertSame(0, User::count());
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_login_intent_is_the_default_and_unknown_intents_are_treated_as_login(): void
    {
        $this->mockGoogle($this->googleUser());

        $this->get('/auth/google/redirect?nonce='.self::NONCE)->assertSessionHas('google_oauth_intent', 'login');
        $this->get('/auth/google/redirect?nonce='.self::NONCE.'&intent=admin')->assertSessionHas('google_oauth_intent', 'login');
        $this->get('/auth/google/redirect?nonce='.self::NONCE.'&intent=signup')->assertSessionHas('google_oauth_intent', 'signup');

        // Sem intenção na sessão (ex.: sessão antiga): vale login — não cria conta.
        config(['auth.registration_enabled' => true]);
        $this->flushSession();
        $response = $this->withSession(['google_oauth_nonce' => hash('sha256', self::NONCE)])->get('/auth/google/callback?code=x&state=xyz');
        $this->assertSame('no_account', $this->loginErrorFrom($response));
        $this->assertSame(0, User::count());
    }

    public function test_signup_with_google_for_an_existing_account_just_logs_in(): void
    {
        config(['auth.registration_enabled' => true]);
        $user = User::factory()->create(['email' => 'maria@gmail.com']);
        $this->mockGoogle($this->googleUser());

        $code = $this->codeFrom($this->signupCallback());

        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => self::NONCE])
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertSame(1, User::count());
    }

    public function test_already_linked_google_account_logs_in_the_same_user_even_if_the_email_changed(): void
    {
        $user = User::factory()->create(['email' => 'antigo@gmail.com']);
        $user->forceFill(['google_id' => '1098765432101234567890'])->save();
        $this->mockGoogle($this->googleUser(['email' => 'novo@gmail.com']));

        $code = $this->codeFrom($this->googleCallback());
        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => self::NONCE])
            ->assertOk()->assertJsonPath('user.id', $user->id);

        $this->assertSame(1, User::count());
    }

    public function test_traditional_account_with_same_verified_email_is_linked_not_duplicated(): void
    {
        $user = User::factory()->create(['email' => 'Maria@Gmail.com', 'password' => 'SenhaAntiga123']);
        $this->mockGoogle($this->googleUser());

        $code = $this->codeFrom($this->googleCallback());
        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => self::NONCE])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.has_password', true);

        $user->refresh();
        $this->assertSame('1098765432101234567890', $user->google_id);
        $this->assertNotNull($user->google_linked_at);
        $this->assertTrue(Hash::check('SenhaAntiga123', $user->password), 'A senha antiga continua valendo.');
        $this->assertSame(1, User::count());
    }

    public function test_linking_an_account_whose_email_was_never_proven_drops_the_old_password_and_sessions(): void
    {
        // Pré-sequestro: alguém cadastrou o e-mail da vítima com a própria
        // senha e ficou com um token. A vítima entra com o Google.
        $user = User::factory()->unverified()->create(['email' => 'maria@gmail.com', 'password' => 'SenhaDoAtacante1']);
        $attackerToken = $user->createToken('atacante')->plainTextToken;
        $this->mockGoogle($this->googleUser());

        $code = $this->codeFrom($this->googleCallback());
        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => self::NONCE])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.has_password', false);

        $user->refresh();
        $this->assertNull($user->password, 'A senha de quem não provou o e-mail deixa de valer.');
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, $user->tokens()->count(), 'Só o token do login com Google sobra.');
        $this->app['auth']->forgetGuards();
        $this->withToken($attackerToken)->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/login', ['email' => 'maria@gmail.com', 'password' => 'SenhaDoAtacante1'])->assertStatus(422);
    }

    public function test_unverified_google_email_is_never_linked_to_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'maria@gmail.com']);
        $this->mockGoogle($this->googleUser(['email_verified' => false]));

        $this->assertSame('unverified_email', $this->loginErrorFrom($this->googleCallback()));
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_email_already_linked_to_another_google_account_is_a_conflict(): void
    {
        $user = User::factory()->create(['email' => 'maria@gmail.com']);
        $user->forceFill(['google_id' => 'outra-conta-google'])->save();
        $this->mockGoogle($this->googleUser());

        $this->assertSame('account_conflict', $this->loginErrorFrom($this->googleCallback()));
        $this->assertSame('outra-conta-google', $user->fresh()->google_id);
    }

    public function test_cancelled_invalid_state_invalid_response_and_expired_session(): void
    {
        $this->mockGoogle($this->googleUser());
        $this->assertSame('cancelled', $this->loginErrorFrom($this->googleCallback(['error' => 'access_denied', 'state' => 'xyz'])));
        $this->assertSame('failed', $this->loginErrorFrom($this->googleCallback(['error' => 'server_error'])));

        // Volta sem o nonce na sessão (outra aba/navegador, sessão expirada).
        $this->assertSame('expired', $this->loginErrorFrom($this->googleCallback(nonce: null)));

        $this->mockGoogle(new InvalidStateException);
        $this->assertSame('invalid_state', $this->loginErrorFrom($this->googleCallback()));

        $this->mockGoogle(new \RuntimeException('Google fora do ar'));
        $this->assertSame('failed', $this->loginErrorFrom($this->googleCallback()));

        $this->mockGoogle($this->googleUser(['email' => 'nao-e-email']));
        $this->assertSame('invalid_profile', $this->loginErrorFrom($this->googleCallback()));

        $this->mockGoogle($this->googleUser(['sub' => '']));
        $this->assertSame('invalid_profile', $this->loginErrorFrom($this->googleCallback()));

        $this->assertSame(0, User::count());
    }

    public function test_exchange_code_is_single_use_bound_to_the_nonce_and_short_lived(): void
    {
        $user = User::factory()->create(['email' => 'maria@gmail.com']);
        $this->mockGoogle($this->googleUser());

        // Nonce de outra aba (login CSRF): recusado — e o código é queimado.
        $code = $this->codeFrom($this->googleCallback());
        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => str_repeat('a', 43)])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => self::NONCE])->assertStatus(422);

        // Reutilização.
        $code = $this->codeFrom($this->googleCallback());
        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => self::NONCE])->assertOk();
        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => self::NONCE])->assertStatus(422);

        // Expirado (60 s).
        $code = $this->codeFrom($this->googleCallback());
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => self::NONCE])->assertStatus(422);

        // Formato inválido.
        $this->postJson('/api/auth/google/exchange', ['code' => ['x'], 'nonce' => self::NONCE])->assertStatus(422);

        $this->assertSame(1, $user->tokens()->count(), 'Só a troca válida gerou token.');
    }

    public function test_google_photo_is_copied_only_from_google_hosts_for_new_accounts(): void
    {
        config(['auth.registration_enabled' => true]);
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Http::fake(['lh3.googleusercontent.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $this->mockGoogle($this->googleUser(['picture' => 'https://lh3.googleusercontent.com/a/foto']));
        $code = $this->codeFrom($this->signupCallback());
        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => self::NONCE])->assertOk();
        $this->assertNotNull(User::where('email', 'maria@gmail.com')->value('profile_photo_path'));

        // Host que não é do Google: nem é baixado (evita SSRF).
        $this->mockGoogle($this->googleUser(['sub' => 'outro', 'email' => 'jose@gmail.com', 'picture' => 'https://169.254.169.254/latest/meta-data']));
        $code = $this->codeFrom($this->signupCallback());
        $this->postJson('/api/auth/google/exchange', ['code' => $code, 'nonce' => self::NONCE])->assertOk();
        $this->assertNull(User::where('email', 'jose@gmail.com')->value('profile_photo_path'));
        Http::assertSentCount(1);
    }

    public function test_google_routes_are_rate_limited(): void
    {
        $this->mockGoogle($this->googleUser());

        for ($i = 0; $i < 20; $i++) {
            $this->get('/auth/google/redirect?nonce='.self::NONCE);
        }

        $this->get('/auth/google/redirect?nonce='.self::NONCE)->assertStatus(429);
    }

    // ============================================== recuperação de senha

    public function test_forgot_password_sends_a_portuguese_email_with_a_frontend_link_and_no_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ana@estudio.test']);

        $this->postJson('/api/forgot-password', ['email' => 'ana@estudio.test'])
            ->assertOk()->assertJsonPath('message', AuthController::FORGOT_PASSWORD_MESSAGE);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
            $mail = $notification->toMail($user);
            $html = (string) $mail->render();

            $this->assertSame('Redefinição de senha — SnapManager', $mail->subject);
            $this->assertStringStartsWith('https://snapmanager.vercel.app/reset-password?token=', $mail->actionUrl);
            $this->assertStringContainsString('email=ana%40estudio.test', $mail->actionUrl);
            $this->assertStringContainsString('Recebemos uma solicitação para redefinir sua senha no SnapManager.', $html);
            $this->assertStringContainsString('Se você não solicitou esta alteração, ignore este e-mail', $html);
            $this->assertStringNotContainsString('localhost', $mail->actionUrl);

            return true;
        });
    }

    public function test_forgot_password_does_not_reveal_whether_the_email_exists(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'ana@estudio.test']);

        $existing = $this->postJson('/api/forgot-password', ['email' => 'ana@estudio.test'])->assertOk()->json();
        $missing = $this->postJson('/api/forgot-password', ['email' => 'ninguem@estudio.test'])->assertOk()->json();

        $this->assertSame($existing, $missing);
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);

        // A tela de redefinição também não confirma e-mails.
        $this->postJson('/api/reset-password', [
            'token' => 'qualquer', 'email' => 'ninguem@estudio.test',
            'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', 'Este link de redefinição é inválido ou expirou. Solicite um novo link.');
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'ana@estudio.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'ana@estudio.test'])->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'ana@estudio.test'])->assertStatus(429);
        // O broker do Laravel também limita a 1 e-mail por minuto por conta.
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    private function resetPayload(string $token, array $overrides = []): array
    {
        return array_merge([
            'token' => $token,
            'email' => 'ana@estudio.test',
            'password' => 'NovaSenha456',
            'password_confirmation' => 'NovaSenha456',
        ], $overrides);
    }

    public function test_reset_password_valid_invalid_expired_reused_and_mismatched(): void
    {
        $user = User::factory()->create(['email' => 'ana@estudio.test', 'password' => 'SenhaAntiga123']);

        // Token inválido.
        $this->postJson('/api/reset-password', $this->resetPayload('token-errado'))->assertStatus(422);

        // Confirmação diferente.
        $token = Password::createToken($user);
        $this->postJson('/api/reset-password', $this->resetPayload($token, ['password_confirmation' => 'Outra123456']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        // Igual à senha atual: recusada — e o link continua valendo.
        $this->postJson('/api/reset-password', $this->resetPayload($token, ['password' => 'SenhaAntiga123', 'password_confirmation' => 'SenhaAntiga123']))
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'A nova senha deve ser diferente da senha atual.');

        // Válida.
        $this->postJson('/api/reset-password', $this->resetPayload($token))
            ->assertOk()->assertJsonPath('message', 'Senha redefinida com sucesso.');
        $this->assertTrue(Hash::check('NovaSenha456', $user->fresh()->password));

        // Reutilização do mesmo link.
        $this->postJson('/api/reset-password', $this->resetPayload($token, ['password' => 'Terceira789', 'password_confirmation' => 'Terceira789']))
            ->assertStatus(422);
        $this->assertTrue(Hash::check('NovaSenha456', $user->fresh()->password));

        // Expirado (60 min).
        $token = Password::createToken($user->fresh());
        $this->travel(61)->minutes();
        $this->postJson('/api/reset-password', $this->resetPayload($token, ['password' => 'Quarta12345', 'password_confirmation' => 'Quarta12345']))
            ->assertStatus(422);
    }

    public function test_google_only_account_can_create_its_first_password_through_the_email_link(): void
    {
        $user = User::factory()->create(['email' => 'ana@estudio.test']);
        $user->forceFill(['password' => null, 'google_id' => 'google-ana'])->save();
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', $this->resetPayload($token))->assertOk();

        $user->refresh();
        $this->assertTrue($user->hasPassword());
        $this->assertNotNull($user->email_verified_at, 'O link de redefinição comprova o e-mail.');
        $this->assertSame('google-ana', $user->google_id, 'Continua entrando com o Google (conta híbrida).');
        $this->postJson('/api/login', ['email' => 'ana@estudio.test', 'password' => 'NovaSenha456'])->assertOk();
    }

    public function test_logged_in_google_only_user_cannot_set_a_password_without_the_email_link(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['password' => null, 'google_id' => 'google-x'])->save();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->putJson('/api/profile/password', [
            'password' => 'NovaSenha456',
            'password_confirmation' => 'NovaSenha456',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertNull($user->fresh()->password);
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('has_password', false)->assertJsonPath('google_linked', true);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    // ======================================================= e-mail (Brevo)

    public function test_brevo_transport_sends_through_the_https_api(): void
    {
        config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'xkeysib-chave-falsa', 'mail.from.address' => 'contato@snapmanager.test', 'mail.from.name' => 'SnapManager']);
        Http::fake([BrevoTransport::ENDPOINT => Http::response(['messageId' => '<abc@brevo>'], 201)]);
        $user = User::factory()->create(['email' => 'ana@estudio.test', 'name' => 'Ana']);

        $user->notify(new ResetPasswordNotification('token-123'));

        Http::assertSent(function (HttpRequest $request) {
            $this->assertSame('xkeysib-chave-falsa', $request->header('api-key')[0] ?? null);
            $this->assertSame(['email' => 'contato@snapmanager.test', 'name' => 'SnapManager'], $request['sender']);
            $this->assertSame('ana@estudio.test', $request['to'][0]['email']);
            $this->assertSame('Redefinição de senha — SnapManager', $request['subject']);
            $this->assertStringContainsString('Redefinir senha', $request['htmlContent']);
            $this->assertStringContainsString('Redefinir senha', $request['textContent']);

            return $request->url() === BrevoTransport::ENDPOINT;
        });
    }

    public function test_brevo_never_exposes_bcc_recipients_as_to(): void
    {
        config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'xkeysib-chave-falsa']);
        Http::fake([BrevoTransport::ENDPOINT => Http::response(['messageId' => '<abc@brevo>'], 201)]);

        Mail::raw('teste', fn ($message) => $message->to('ana@estudio.test')->bcc('oculto@estudio.test')->subject('x'));

        Http::assertSent(function (HttpRequest $request) {
            $this->assertSame(['ana@estudio.test'], array_column($request['to'], 'email'));
            $this->assertSame(['oculto@estudio.test'], array_column($request['bcc'], 'email'));

            return true;
        });

        // Sem "Para": recusa antes de chamar a API (não promove o Bcc a To).
        try {
            Mail::raw('teste', fn ($message) => $message->bcc('oculto@estudio.test')->subject('x'));
            $this->fail('Deveria falhar.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('destinatário', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_brevo_errors_do_not_leak_the_api_key_and_do_not_break_forgot_password(): void
    {
        config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'xkeysib-chave-falsa']);
        Http::fake([BrevoTransport::ENDPOINT => Http::response(['code' => 'unauthorized', 'message' => 'Key not found'], 401)]);
        User::factory()->create(['email' => 'ana@estudio.test']);

        try {
            Mail::raw('teste', fn ($message) => $message->to('ana@estudio.test')->subject('x'));
            $this->fail('Deveria falhar.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringNotContainsString('xkeysib', $e->getMessage());
        }

        // Para o usuário, a resposta continua a mesma (e-mail sai depois da resposta).
        $this->postJson('/api/forgot-password', ['email' => 'ana@estudio.test'])
            ->assertOk()->assertJsonPath('message', AuthController::FORGOT_PASSWORD_MESSAGE);
    }
}
