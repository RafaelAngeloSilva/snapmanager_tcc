<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * E-mail de redefinição de senha do SnapManager, em português.
 *
 * Mantém tudo o que o ResetPassword do Laravel já faz de seguro (token
 * gerado e guardado como hash pelo password broker, validade em
 * config/auth.php, uso único) e só troca o TEXTO. O link aponta para o
 * front-end (FRONTEND_URL), montado em AppServiceProvider
 * (ResetPassword::createUrlUsing).
 *
 * O e-mail NUNCA contém a senha — só o link para criar uma nova.
 */
class ResetPasswordNotification extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Redefinição de senha — SnapManager')
            ->greeting('Olá!')
            ->line('Recebemos uma solicitação para redefinir sua senha no SnapManager.')
            ->line('Clique no botão abaixo para criar uma nova senha.')
            ->action('Redefinir senha', $url)
            ->line("Por segurança, este link expira em {$minutes} minutos e só pode ser usado uma vez.")
            ->line('Se você não solicitou esta alteração, ignore este e-mail — sua senha atual continua a mesma.')
            ->salutation('Equipe SnapManager');
    }
}
