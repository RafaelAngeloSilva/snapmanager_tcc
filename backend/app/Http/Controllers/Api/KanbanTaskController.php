<?php

namespace App\Http\Controllers\Api;

use App\Enums\KanbanPriority;
use App\Http\Controllers\Controller;
use App\Http\Requests\MoveKanbanTaskRequest;
use App\Http\Requests\StoreKanbanTaskRequest;
use App\Http\Requests\UpdateKanbanTaskRequest;
use App\Http\Resources\KanbanTaskResource;
use App\Models\Appointment;
use App\Models\KanbanColumn;
use App\Models\KanbanTask;
use App\Models\Order;
use App\Services\KanbanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class KanbanTaskController extends Controller
{
    /**
     * Relacionamentos carregados para devolver um card completo — o mesmo
     * conjunto enxuto que o quadro usa (ver KanbanTaskResource).
     *
     * @var list<string>
     */
    private const CARD_RELATIONS = ['assignee', 'client', 'appointment', 'order', 'gallery'];

    public function __construct(private readonly KanbanService $kanban) {}

    public function show(KanbanTask $task): KanbanTaskResource
    {
        Gate::authorize('view', $task);

        return KanbanTaskResource::make($task->load(self::CARD_RELATIONS));
    }

    /**
     * Demanda avulsa ("montar álbum", "fazer backup"): não nasce de nenhum
     * registro do sistema, embora possa opcionalmente apontar para um
     * cliente/galeria.
     */
    public function store(StoreKanbanTaskRequest $request): JsonResponse
    {
        Gate::authorize('create', KanbanTask::class);

        $data = $request->validated();
        $column = KanbanColumn::findOrFail($data['kanban_column_id']);

        $task = new KanbanTask([
            ...$data,
            'priority' => $data['priority'] ?? KanbanPriority::Medium->value,
            'position' => $this->kanban->nextPosition($column->id),
            'created_by' => $request->user()->id,
        ]);

        $this->kanban->applyCompletion($task, $column);
        $task->save();

        return KanbanTaskResource::make($task->load(self::CARD_RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateKanbanTaskRequest $request, KanbanTask $task): KanbanTaskResource
    {
        Gate::authorize('update', $task);

        $task->update($request->validated());

        return KanbanTaskResource::make($task->load(self::CARD_RELATIONS));
    }

    public function destroy(KanbanTask $task): JsonResponse
    {
        Gate::authorize('delete', $task);

        $columnId = $task->kanban_column_id;
        $task->delete();
        $this->kanban->reorderColumn($columnId);

        return response()->json(null, 204);
    }

    /**
     * Arrastar card: muda coluna e/ou posição. O recálculo das posições e o
     * carimbo de conclusão ficam no KanbanService.
     */
    public function move(MoveKanbanTaskRequest $request, KanbanTask $task): KanbanTaskResource
    {
        Gate::authorize('move', $task);

        $column = KanbanColumn::findOrFail($request->validated('kanban_column_id'));
        $task = $this->kanban->moveTask($task, $column, (int) $request->validated('position'));

        return KanbanTaskResource::make($task->load(self::CARD_RELATIONS));
    }

    /**
     * Agendamento → card. Copia os dados que o quadro precisa mostrar
     * (título, cliente, profissional responsável, data da sessão) e guarda
     * o vínculo; o agendamento em si continua sendo a fonte da verdade.
     */
    public function storeFromAppointment(Request $request, Appointment $appointment): JsonResponse
    {
        if ($existing = KanbanTask::where('appointment_id', $appointment->id)->first()) {
            return $this->alreadyOnBoard($existing, 'Este agendamento já está no quadro de produção.');
        }

        $column = $this->kanban->defaultColumn();

        if (! $column) {
            return $this->missingColumn();
        }

        $task = new KanbanTask([
            'kanban_column_id' => $column->id,
            'title' => $appointment->title,
            'description' => $appointment->session_type
                ? "Sessão: {$appointment->session_type}"
                : null,
            // O profissional do agendamento já vira o responsável do card.
            'assigned_to' => $appointment->user_id,
            'priority' => KanbanPriority::Medium->value,
            'due_date' => $appointment->starts_at?->toDateString(),
            'position' => $this->kanban->nextPosition($column->id),
            'client_id' => $appointment->client_id,
            'appointment_id' => $appointment->id,
            'created_by' => $request->user()->id,
        ]);

        $this->kanban->applyCompletion($task, $column);
        $task->save();

        return KanbanTaskResource::make($task->load(self::CARD_RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Pedido → card. Mesma ideia: o prazo do pedido vira o prazo do card,
     * mas mover o card NÃO altera o OrderStatus (ver README — as duas
     * regras são deliberadamente independentes nesta versão).
     */
    public function storeFromOrder(Request $request, Order $order): JsonResponse
    {
        if ($existing = KanbanTask::where('order_id', $order->id)->first()) {
            return $this->alreadyOnBoard($existing, 'Este pedido já está no quadro de produção.');
        }

        $column = $this->kanban->defaultColumn();

        if (! $column) {
            return $this->missingColumn();
        }

        $task = new KanbanTask([
            'kanban_column_id' => $column->id,
            'title' => $order->title,
            'description' => $order->service_type ? "Serviço: {$order->service_type}" : null,
            'priority' => KanbanPriority::Medium->value,
            'due_date' => $order->due_date?->toDateString(),
            'position' => $this->kanban->nextPosition($column->id),
            'client_id' => $order->client_id,
            'order_id' => $order->id,
            'appointment_id' => null,
            'created_by' => $request->user()->id,
        ]);

        $this->kanban->applyCompletion($task, $column);
        $task->save();

        return KanbanTaskResource::make($task->load(self::CARD_RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * 409 com o card existente no corpo: o front-end usa isso para oferecer
     * "Ver no Kanban" em vez de criar uma duplicata. O `code` separa este
     * 409 do de "quadro sem colunas" (missingColumn).
     */
    private function alreadyOnBoard(KanbanTask $task, string $message): JsonResponse
    {
        return response()->json([
            'code' => 'already_on_board',
            'message' => $message,
            'data' => KanbanTaskResource::make($task->load(self::CARD_RELATIONS)),
        ], 409);
    }

    private function missingColumn(): JsonResponse
    {
        return response()->json([
            'code' => 'no_columns',
            'message' => 'O quadro de produção ainda não possui colunas. Um administrador precisa criar uma coluna antes.',
        ], 409);
    }
}
