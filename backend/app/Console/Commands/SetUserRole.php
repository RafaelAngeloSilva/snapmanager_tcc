<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Define o perfil de um usuário pelo e-mail.
 *
 * O cadastro público (e o login com Google) sempre cria ATENDENTE, e não há
 * tela para promover usuários. Este comando é o caminho seguro para criar o
 * primeiro administrador de uma instalação nova, pelo terminal do servidor
 * (só quem tem acesso à hospedagem consegue rodá-lo).
 *
 *   php artisan users:set-role pessoa@exemplo.com admin
 */
class SetUserRole extends Command
{
    protected $signature = 'users:set-role {email : E-mail da conta} {role : admin, attendant, photographer ou editor}';

    protected $description = 'Define o perfil (role) de um usuário existente';

    public function handle(): int
    {
        $role = UserRole::tryFrom(strtolower(trim((string) $this->argument('role'))));

        if (! $role) {
            $this->error('Perfil inválido. Use: '.implode(', ', array_column(UserRole::cases(), 'value')).'.');

            return self::INVALID;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        if (! $user) {
            $this->error('Nenhum usuário com esse e-mail.');

            return self::FAILURE;
        }

        $previous = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

        $user->forceFill(['role' => $role->value])->save();

        $this->info("Perfil de {$user->email}: {$previous} → {$role->value}.");

        return self::SUCCESS;
    }
}
