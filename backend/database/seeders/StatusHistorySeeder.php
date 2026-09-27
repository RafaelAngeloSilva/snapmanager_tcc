<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\StatusHistory;
use App\Models\User;
use Illuminate\Database\Seeder;

class StatusHistorySeeder extends Seeder
{
    public function run(): void
    {
        $attendant = User::where('role', 'attendant')->first();
        $photographer = User::where('role', 'photographer')->first();
        $editor = User::where('role', 'editor')->first();

        $albumOrder = Order::where('title', 'Álbum 30x30 - Ensaio gestante')->first();
        $bookOrder = Order::where('title', 'Edição e entrega digital - Book')->first();
        $eventOrder = Order::where('title', 'Cobertura fotográfica evento corporativo')->first();
        $printOrder = Order::where('title', 'Impressão avulsa 20x30')->first();
        $overdueOrder = Order::where('title', 'Retratos corporativos - equipe')->first();
        $readyOrder = Order::where('title', 'Ensaio newborn - pronto')->first();

        // Cria o registro de histórico já com um "created_at" retroativo, pra
        // não deixar todas as transições com o mesmíssimo timestamp (o que
        // ficaria estranho numa demonstração ao vivo). O comportamento real
        // da API (OrderController::store/updateStatus) já grava o momento
        // exato de cada mudança; aqui só simulamos uma linha do tempo.
        $log = function (Order $order, ?string $from, string $to, int $userId, ?string $notes, $when) {
            $history = StatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $userId,
                'notes' => $notes,
            ]);

            $history->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
        };

        // Álbum 30x30 - Ensaio gestante (pending): acabou de entrar.
        $log($albumOrder, null, 'pending', $attendant->id, 'Pedido criado.', now()->subDay());

        // Edição e entrega digital - Book (in_editing): em andamento.
        $log($bookOrder, null, 'pending', $attendant->id, 'Pedido criado.', now()->subDays(2));
        $log($bookOrder, 'pending', 'in_editing', $editor->id, 'Edição das fotos iniciada.', now()->subDay());

        // Cobertura fotográfica evento corporativo (delivered): fluxo
        // completo, do agendamento até a entrega — usado no passo 11 do
        // roteiro de demonstração.
        $log($eventOrder, null, 'pending', $attendant->id, 'Pedido criado a partir do evento.', now()->subDays(6));
        $log($eventOrder, 'pending', 'captured', $photographer->id, 'Fotos capturadas durante o evento.', now()->subDays(5));
        $log($eventOrder, 'captured', 'in_editing', $editor->id, null, now()->subDays(4));
        $log($eventOrder, 'in_editing', 'in_review', $editor->id, 'Aguardando aprovação do cliente.', now()->subDays(2));
        $log($eventOrder, 'in_review', 'ready_for_delivery', $attendant->id, 'Cliente aprovou o material.', now()->subDay());
        $log($eventOrder, 'ready_for_delivery', 'delivered', $attendant->id, 'Link de download enviado ao cliente.', now()->subDay()->addHours(4));

        // Impressão avulsa 20x30 (cancelled).
        $log($printOrder, null, 'pending', $attendant->id, 'Pedido criado.', now()->subDays(4));
        $log($printOrder, 'pending', 'cancelled', $attendant->id, 'Cliente desistiu do pedido.', now()->subDays(3));

        // Retratos corporativos - equipe (in_review, atrasado): editoração
        // travou na revisão, por isso passou do prazo — bom gancho pro
        // passo 3 (alertas) do roteiro de demonstração.
        $log($overdueOrder, null, 'pending', $attendant->id, 'Pedido criado.', now()->subDays(10));
        $log($overdueOrder, 'pending', 'captured', $photographer->id, 'Retratos da equipe capturados no estúdio.', now()->subDays(9));
        $log($overdueOrder, 'captured', 'in_editing', $editor->id, null, now()->subDays(7));
        $log($overdueOrder, 'in_editing', 'in_review', $editor->id, 'Aguardando validação do RH da empresa.', now()->subDays(4));

        // Ensaio newborn - pronto (ready_for_delivery): fluxo completo até
        // ficar pronto pra retirada.
        $log($readyOrder, null, 'pending', $attendant->id, 'Pedido criado.', now()->subDays(5));
        $log($readyOrder, 'pending', 'captured', $photographer->id, 'Ensaio realizado na casa da família.', now()->subDays(4));
        $log($readyOrder, 'captured', 'in_editing', $editor->id, null, now()->subDays(3));
        $log($readyOrder, 'in_editing', 'in_review', $editor->id, 'Aguardando aprovação da seleção de fotos.', now()->subDays(2));
        $log($readyOrder, 'in_review', 'ready_for_delivery', $attendant->id, 'Álbum físico finalizado, aguardando retirada pelo cliente.', now()->subDay());
    }
}
