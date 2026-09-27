<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // statusHistories NÃO é carregado aqui de propósito: a tabela de
        // pedidos não exibe o histórico, só a tela de detalhes (show/update/
        // updateStatus, abaixo, continuam carregando). Evita ~2 queries de
        // relacionamento por página de listagem que nunca chegam a ser
        // usadas pelo front-end (medido: 6 queries → 4 nesse endpoint).
        $orders = Order::query()
            ->with(['client', 'appointment'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('client_id'), fn ($query) => $query->where('client_id', $request->input('client_id')))
            ->when($request->filled('due_date'), fn ($query) => $query->whereDate('due_date', $request->input('due_date')))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = DB::transaction(function () use ($request) {
            $order = Order::create($request->validated());
            // "status"/"total" may have been omitted and filled in by the DB
            // column default; refresh so the in-memory model (and the history
            // entry below) reflect the actual persisted values.
            $order->refresh();

            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => $order->status,
                'changed_by' => $request->user()->id,
                'notes' => 'Pedido criado.',
            ]);

            return $order;
        });

        $order->load(['client', 'appointment', 'statusHistories.user']);

        return OrderResource::make($order)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        return OrderResource::make(
            $order->load(['client', 'appointment', 'statusHistories' => fn ($query) => $query->with('user')->orderBy('created_at')])
        );
    }

    public function update(UpdateOrderRequest $request, Order $order): OrderResource
    {
        $order->update($request->validated());

        return OrderResource::make(
            $order->load(['client', 'appointment', 'statusHistories' => fn ($query) => $query->with('user')->orderBy('created_at')])
        );
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        // Convertido pra enum antes de comparar: $order->status já vem como
        // OrderStatus (cast do model), então comparar com a string crua da
        // validação sempre daria "diferente" e nunca cairia no atalho abaixo.
        $newStatus = OrderStatus::from($request->validated('status'));
        $previousStatus = $order->status;

        if ($newStatus === $previousStatus) {
            $order->load(['client', 'appointment', 'statusHistories' => fn ($query) => $query->with('user')->orderBy('created_at')]);

            return response()->json([
                'message' => 'O status informado é igual ao status atual. Nenhuma alteração foi registrada.',
                'data' => new OrderResource($order),
            ]);
        }

        DB::transaction(function () use ($order, $newStatus, $previousStatus, $request) {
            $order->update(['status' => $newStatus]);

            $order->statusHistories()->create([
                'from_status' => $previousStatus,
                'to_status' => $newStatus,
                'changed_by' => $request->user()->id,
                'notes' => $request->validated('notes'),
            ]);
        });

        $order->load(['client', 'appointment', 'statusHistories' => fn ($query) => $query->with('user')->orderBy('created_at')]);

        return response()->json([
            'message' => 'Status atualizado com sucesso.',
            'data' => new OrderResource($order),
        ]);
    }

    public function destroy(Order $order): JsonResponse
    {
        $order->delete();

        return response()->json(null, 204);
    }
}
