<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Document;
use App\Models\User;

/**
 * Autorização de documentos — quem pode fazer o quê, em qual registro.
 *
 * O middleware `role` nas rotas já barra o perfil errado de cara (403);
 * esta Policy acrescenta a checagem POR REGISTRO: todo método que recebe um
 * Document exige antes que ele seja visível ao usuário (mesma regra da
 * listagem, Document::isVisibleTo), o que fecha a porta para IDOR — trocar
 * o id na URL não expõe o documento de outro contexto.
 *
 * Se a ação é possível no ESTADO atual do documento (ex.: só rascunho é
 * editável) é outra pergunta, respondida pelo DocumentSignatureService /
 * controller com 409 — permissão e estado ficam separados.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Attendant, UserRole::Photographer, UserRole::Editor], true);
    }

    public function view(User $user, Document $document): bool
    {
        return $document->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    /** Editar rascunho, gerenciar signatários. */
    public function update(User $user, Document $document): bool
    {
        return $this->manages($user) && $document->isVisibleTo($user);
    }

    /** Enviar, notificar, sincronizar e descartar envio com falha. */
    public function operate(User $user, Document $document): bool
    {
        return $this->manages($user) && $document->isVisibleTo($user);
    }

    /**
     * Baixar os PDFs (original e assinado). Fotógrafo e editor têm só
     * leitura dos DADOS do documento: o conteúdo do contrato fica com quem
     * gerencia o atendimento.
     */
    public function downloadFiles(User $user, Document $document): bool
    {
        return $this->manages($user) && $document->isVisibleTo($user);
    }

    public function cancel(User $user, Document $document): bool
    {
        return $user->role === UserRole::Admin && $document->isVisibleTo($user);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->role === UserRole::Admin && $document->isVisibleTo($user);
    }

    /**
     * Dados de contato dos signatários (e-mail/telefone). Depende só do
     * perfil — chamado como $user->can('viewSignerContacts', Document::class).
     */
    public function viewSignerContacts(User $user): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Attendant], true);
    }
}
