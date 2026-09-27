<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Acompanhe seu Pedido" — página pública do cliente (sem login).
 *
 * Só expõe o necessário para o cliente entender o andamento: título, tipo de
 * serviço, etapa atual, prazo e as datas em que cada etapa foi alcançada.
 * NUNCA sai daqui: valor, observações internas, notas do histórico, nomes
 * de funcionários, contatos ou ids internos.
 *
 * Token inexistente, revogado ou de pedido excluído → 404 (mesma resposta
 * para os três casos, para não revelar se um token já existiu).
 */
class PublicOrderTrackingController extends Controller
{
    /** Etapas exibidas na linha do tempo, em ordem (Cancelado fica de fora). */
    private const STEPS = [
        OrderStatus::Pending,
        OrderStatus::Captured,
        OrderStatus::InEditing,
        OrderStatus::InReview,
        OrderStatus::ReadyForDelivery,
        OrderStatus::Delivered,
    ];

    /** Texto amigável para o cliente sobre a etapa atual. */
    private const STATUS_MESSAGES = [
        'pending' => 'Recebemos seu pedido e ele está na fila de produção.',
        'captured' => 'As fotos já foram feitas e seguem para a próxima etapa.',
        'in_editing' => 'Suas fotos estão sendo editadas.',
        'in_review' => 'Estamos revisando o material antes de liberar.',
        'ready_for_delivery' => 'Seu pedido está pronto! Em breve o estúdio combina a entrega.',
        'delivered' => 'Pedido entregue. Obrigado pela confiança!',
        'cancelled' => 'Este pedido foi cancelado. Em caso de dúvida, fale com o estúdio.',
    ];

    public function show(Request $request, string $token): JsonResponse
    {
        $order = Order::query()
            ->where('tracking_token', $token)
            ->with([
                'client:id,name',
                'statusHistories' => fn ($query) => $query->select(['id', 'order_id', 'to_status', 'created_at'])->orderBy('created_at')->orderBy('id'),
            ])
            ->firstOrFail();

        // "Visualizar como cliente" (botão da equipe) manda ?preview=1 para
        // não contar como visita do cliente nem disparar o alerta.
        if (! $request->boolean('preview')) {
            $order->registerTrackingView();
        }

        $status = $order->status;
        $reachedAt = $this->firstReachedAt($order);
        $currentIndex = array_search($status, self::STEPS, true);

        $steps = array_map(function (OrderStatus $step, int $index) use ($currentIndex, $reachedAt, $status) {
            $state = match (true) {
                $status === OrderStatus::Cancelled => isset($reachedAt[$step->value]) ? 'done' : 'upcoming',
                $index < $currentIndex => 'done',
                $index === $currentIndex => $step === OrderStatus::Delivered ? 'done' : 'current',
                default => 'upcoming',
            };

            return [
                'status' => $step->value,
                'label' => $step->label(),
                'state' => $state,
                'reached_at' => $state === 'upcoming' ? null : ($reachedAt[$step->value] ?? null),
            ];
        }, self::STEPS, array_keys(self::STEPS));

        $firstName = trim(strtok((string) ($order->client?->name ?? ''), ' ') ?: '');

        return response()->json([
            'data' => [
                'title' => $order->title,
                'service_type' => $order->service_type,
                'status' => $status->value,
                'status_label' => $status->label(),
                'status_message' => self::STATUS_MESSAGES[$status->value] ?? null,
                'is_cancelled' => $status === OrderStatus::Cancelled,
                'is_delivered' => $status === OrderStatus::Delivered,
                'due_date' => $order->due_date?->toDateString(),
                'client_first_name' => $firstName !== '' ? $firstName : null,
                'steps' => $steps,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
            ],
        ]);
    }

    /**
     * Data em que cada status foi alcançado pela primeira vez, a partir do
     * histórico (sem notas nem autor).
     *
     * @return array<string, string>
     */
    private function firstReachedAt(Order $order): array
    {
        $reached = [];

        foreach ($order->statusHistories as $history) {
            $value = $history->to_status instanceof OrderStatus ? $history->to_status->value : (string) $history->to_status;
            $reached[$value] ??= $history->created_at?->toIso8601String();
        }

        // Pedidos antigos podem não ter o registro inicial no histórico.
        $reached[OrderStatus::Pending->value] ??= $order->created_at?->toIso8601String();

        return $reached;
    }
}
