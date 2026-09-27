<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\KanbanTask;
use App\Models\User;

/**
 * Primeira Policy do projeto — e ela existe por um motivo concreto: aqui a
 * autorização depende do REGISTRO, não só do perfil. Admin e atendimento
 * administram qualquer card; fotógrafo e editor mexem apenas nos cards
 * atribuídos a eles. Middleware de role sozinho não expressa "só se a
 * tarefa for sua", daí a Policy.
 *
 * As regras que dependem só do perfil (gerenciar colunas do quadro)
 * continuam no middleware `role:` em routes/api.php.
 */
class KanbanTaskPolicy
{
    /**
     * Perfis que administram o quadro inteiro.
     */
    private function managesBoard(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Attendant], true);
    }

    private function isAssignee(User $user, KanbanTask $task): bool
    {
        return $task->assigned_to !== null && $task->assigned_to === $user->id;
    }

    public function viewAny(User $user): bool
    {
        // O quadro de produção é compartilhado: os 4 perfis enxergam.
        return true;
    }

    public function view(User $user, KanbanTask $task): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        // Qualquer perfil pode registrar uma demanda própria ("fazer backup
        // do ensaio", "revisar tratamento") — é o que torna o quadro útil
        // para quem executa, não só para quem administra.
        return true;
    }

    public function update(User $user, KanbanTask $task): bool
    {
        return $this->managesBoard($user) || $this->isAssignee($user, $task);
    }

    /**
     * Mover é a ação mais frequente do quadro e segue a mesma regra de
     * edição: quem administra move qualquer card, quem executa move os
     * seus.
     */
    public function move(User $user, KanbanTask $task): bool
    {
        return $this->update($user, $task);
    }

    public function delete(User $user, KanbanTask $task): bool
    {
        return $this->managesBoard($user) || $this->isAssignee($user, $task);
    }
}
