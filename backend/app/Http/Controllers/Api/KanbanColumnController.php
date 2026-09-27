<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyKanbanColumnRequest;
use App\Http\Requests\ReorderKanbanColumnsRequest;
use App\Http\Requests\StoreKanbanColumnRequest;
use App\Http\Requests\UpdateKanbanColumnRequest;
use App\Http\Resources\KanbanColumnResource;
use App\Models\KanbanColumn;
use App\Services\KanbanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Gestão da estrutura do quadro. Todas as rotas deste controller estão sob
 * `role:admin` (ver routes/api.php): montar as etapas da produção é uma
 * decisão administrativa, não operacional.
 */
class KanbanColumnController extends Controller
{
    public function __construct(private readonly KanbanService $kanban) {}

    public function store(StoreKanbanColumnRequest $request): JsonResponse
    {
        $column = KanbanColumn::create([
            ...$request->validated(),
            'position' => (int) KanbanColumn::max('position') + 1,
            'created_by' => $request->user()->id,
        ]);

        // refresh() para trazer o default do banco (is_done = false) quando
        // o campo não foi enviado no request.
        return KanbanColumnResource::make($column->refresh())
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateKanbanColumnRequest $request, KanbanColumn $column): KanbanColumnResource
    {
        $column->update($request->validated());

        // Alternar is_done reavalia os cards que já estão na coluna: virar
        // "coluna de conclusão" marca o que está lá como concluído, e o
        // inverso limpa.
        if ($request->has('is_done')) {
            $column->tasks()->each(function ($task) use ($column) {
                $this->kanban->applyCompletion($task, $column);
                $task->save();
            });
        }

        return KanbanColumnResource::make($column->refresh());
    }

    /**
     * Nunca apaga tarefas em silêncio: se a coluna tem cards, o request
     * exige `move_tasks_to` (ver DestroyKanbanColumnRequest) e os cards são
     * realocados antes da exclusão.
     */
    public function destroy(DestroyKanbanColumnRequest $request, KanbanColumn $column): JsonResponse
    {
        if (KanbanColumn::count() === 1) {
            return response()->json([
                'message' => 'O quadro precisa ter pelo menos uma coluna.',
            ], 409);
        }

        DB::transaction(function () use ($request, $column) {
            if ($destinationId = $request->validated('move_tasks_to')) {
                $this->kanban->moveAllTasks($column, KanbanColumn::findOrFail($destinationId));
            }

            $column->delete();

            KanbanColumn::orderBy('position')->orderBy('id')->get()
                ->each(fn (KanbanColumn $remaining, int $index) => $remaining->update(['position' => $index]));
        });

        return response()->json(null, 204);
    }

    public function reorder(ReorderKanbanColumnsRequest $request): AnonymousResourceCollection
    {
        DB::transaction(function () use ($request) {
            foreach ($request->validated('columns') as $position => $id) {
                KanbanColumn::whereKey($id)->update(['position' => $position]);
            }
        });

        return KanbanColumnResource::collection(
            KanbanColumn::orderBy('position')->orderBy('id')->get()
        );
    }
}
