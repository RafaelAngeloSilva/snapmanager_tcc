<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\GoogleLoginException;
use App\Models\User;
use App\Support\FieldLimits;
use App\Support\FileKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Throwable;

/**
 * Encontra, vincula ou cria o usuário do SnapManager para uma conta Google.
 *
 * Ordem (nunca cria duas contas para a mesma pessoa):
 *  1. pelo google_id ("sub" do Google — estável mesmo se o e-mail mudar);
 *  2. pelo e-mail, SÓ se o Google confirmou que o e-mail é verificado —
 *     aí vincula o google_id à conta existente. Se o e-mail dessa conta já
 *     tinha sido comprovado (email_verified_at: Google ou link de
 *     redefinição de senha), a senha continua valendo. Se NUNCA foi
 *     comprovado, quem criou a conta pode não ser o dono do e-mail
 *     ("pré-sequestro": alguém cadastra o e-mail da vítima com uma senha
 *     dele e espera ela entrar com o Google). Nesse caso a senha antiga é
 *     removida e todas as sessões são encerradas; o dono cria uma senha nova
 *     pelo "Esqueci minha senha" se quiser;
 *  3. cria a conta (perfil atendente, SEM senha local), apenas se o
 *     cadastro público estiver aberto (REGISTRATION_ENABLED).
 *
 * Nenhum token do Google (access/refresh) é guardado: só o id, o nome, o
 * e-mail e, para conta nova sem foto, uma cópia da foto no armazenamento
 * do próprio sistema.
 */
class GoogleAccountService
{
    /** Hosts de onde a foto do Google pode ser baixada (evita SSRF). */
    private const AVATAR_HOST_SUFFIX = '.googleusercontent.com';

    private const AVATAR_MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly FileStorage $files) {}

    /**
     * @throws GoogleLoginException
     */
    public function resolveUser(SocialiteUser $google): User
    {
        $googleId = (string) $google->getId();
        $email = mb_strtolower(trim((string) $google->getEmail()));
        $raw = (array) ($google->user ?? []);

        if ($googleId === '' || mb_strlen($googleId) > 64 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new GoogleLoginException('invalid_profile');
        }

        // O Google devolve email_verified (bool ou "true"). Sem verificação,
        // o e-mail não prova nada e não pode vincular a uma conta existente.
        if (! filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new GoogleLoginException('unverified_email');
        }

        [$user, $created] = DB::transaction(function () use ($googleId, $email, $google) {
            if ($user = User::where('google_id', $googleId)->lockForUpdate()->first()) {
                return [$user, false];
            }

            $user = User::whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();

            if ($user) {
                // A conta já está ligada a OUTRA conta Google: não troca o
                // vínculo silenciosamente.
                if ($user->google_id !== null && $user->google_id !== $googleId) {
                    throw new GoogleLoginException('account_conflict');
                }

                $emailWasProven = $user->email_verified_at !== null;

                $user->forceFill([
                    'google_id' => $googleId,
                    'google_linked_at' => now(),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);

                if (! $emailWasProven) {
                    $user->password = null;
                    $user->tokens()->delete();
                }

                $user->save();

                Log::info('Conta Google vinculada a usuário existente.', [
                    'user_id' => $user->id,
                    'credenciais_antigas_removidas' => ! $emailWasProven,
                ]);

                return [$user, false];
            }

            if (! config('auth.registration_enabled')) {
                throw new GoogleLoginException('no_account');
            }

            $name = trim((string) ($google->getName() ?: $google->getNickname() ?: strstr($email, '@', true)));

            $user = new User;
            $user->forceFill([
                'name' => mb_substr($name, 0, FieldLimits::PERSON_NAME),
                'email' => $email,
                'password' => null,
                'role' => UserRole::Attendant,
                'google_id' => $googleId,
                'google_linked_at' => now(),
                // O Google já verificou o e-mail.
                'email_verified_at' => now(),
            ])->save();

            Log::info('Conta criada pelo login com Google.', ['user_id' => $user->id]);

            return [$user, true];
        });

        // Fora da transação: o download (até 5 s) não segura locks no banco.
        if ($created) {
            $this->importAvatar($user, $google->getAvatar());
        }

        return $user;
    }

    /**
     * Copia a foto do Google para o armazenamento do sistema — só para conta
     * nova sem foto. Melhor esforço: qualquer falha é ignorada (a conta
     * funciona sem foto).
     */
    private function importAvatar(User $user, ?string $url): void
    {
        if (! $url || $user->profile_photo_path) {
            return;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! str_ends_with($host, self::AVATAR_HOST_SUFFIX)) {
            return;
        }

        try {
            $response = Http::timeout(5)->withOptions(['allow_redirects' => false])->get($url);
            $body = $response->body();

            if (! $response->successful() || strlen($body) === 0 || strlen($body) > self::AVATAR_MAX_BYTES) {
                return;
            }

            $info = @getimagesizefromstring($body);
            $extension = match ($info[2] ?? null) {
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_WEBP => 'webp',
                default => null,
            };

            if ($extension === null) {
                return;
            }

            $path = FileKeys::profilePhoto($extension);
            $this->files->put($path, $body, $info['mime']);
            $user->forceFill(['profile_photo_path' => $path])->save();
        } catch (Throwable $e) {
            Log::info('Foto do Google não importada.', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }
}
