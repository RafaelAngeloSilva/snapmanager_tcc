<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ClientHistoryEntry;
use App\Models\User;

/**
 * Anotações manuais do Histórico do cliente.
 *
 * Ver e criar: os mesmos perfis que acessam clientes (middleware
 * role:admin,attendant,photographer em routes/api.php). Editar e excluir:
 * só o autor da anotação ou um administrador — uma pessoa não reescreve o
 * registro de outra. Eventos automáticos nunca passam por aqui: não são
 * registros editáveis.
 */
class ClientHistoryEntryPolicy
{
    public function update(User $user, ClientHistoryEntry $entry): bool
    {
        return $user->role === UserRole::Admin
            || ($entry->user_id !== null && $entry->user_id === $user->id);
    }

    public function delete(User $user, ClientHistoryEntry $entry): bool
    {
        return $this->update($user, $entry);
    }
}
