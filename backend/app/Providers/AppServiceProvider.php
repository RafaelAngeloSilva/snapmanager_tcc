<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use App\Models\PersonalAccessToken;
use Illuminate\Support\ServiceProvider;
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

        // A app é uma SPA separada do backend (não há rota web "password.reset"
        // aqui), então o link do e-mail/notificação precisa apontar pro
        // front-end React em vez do default do Laravel.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = rtrim(config('app.frontend_url'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($user->email);
        });
    }
}
