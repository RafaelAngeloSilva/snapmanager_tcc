<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * "Acompanhe seu Pedido" — lado interno (equipe): ativar e revogar o link
 * público de acompanhamento de um pedido. Rotas sob role:admin,attendant.
 *
 * A URL pública em si é montada no front-end (origem do app + /acompanhar/
 * {token}), do mesmo jeito que o link da galeria pública.
 */
class OrderTrackingController extends Controller
{
    public function enable(Order $order): JsonResponse
    {
        $alreadyEnabled = $order->hasTracking();
        $order->enableTracking();

        return response()->json([
            'message' => $alreadyEnabled
                ? 'O acompanhamento já estava ativo.'
                : 'Acompanhamento ativado. Envie o link ao cliente.',
            'data' => OrderResource::trackingPayload($order),
        ], $alreadyEnabled ? 200 : 201);
    }

    public function revoke(Order $order): JsonResponse
    {
        $order->revokeTracking();

        return response()->json([
            'message' => 'Link de acompanhamento revogado. O link antigo não funciona mais.',
            'data' => OrderResource::trackingPayload($order),
        ]);
    }
}
