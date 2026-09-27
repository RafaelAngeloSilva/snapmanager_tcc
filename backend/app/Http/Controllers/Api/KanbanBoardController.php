<?php

namespace App\Http\Controllers\Api;

use App\Enums\KanbanTaskOrigin;
use App\Http\Controllers\Controller;
use App\Http\Resources\KanbanColumnResource;
use App\Models\KanbanColumn;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class KanbanBoardController extends Controller
{
    /**
     * Quadro completo: colunas em ordem, cada uma com seus cards em ordem.
     *
     * Os filtros são aplicados na QUERY (não no front-end): "Minhas
     * tarefas", responsável, prioridade, origem e cliente viram condições
     * do SELECT, então o navegador nunca recebe cards que não vai mostrar.
     *
     * Custo fixo de queries, independente do número de cards: 1 para as
     * colunas, 1 para as tarefas e 1 por relacionamento carregado — sem
     * N+1.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $columns = KanbanColumn::query()
            ->with(['tasks' => function ($query) use ($request) {
                $query
                    ->with(['assignee', 'client', 'appointment', 'order', 'gallery'])
                    ->orderBy('position')
                    ->orderBy('id');

                $this->applyFilters($query, $request);
            }])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return KanbanColumnResource::collection($columns);
    }

    /**
     * Recebe a relação do eager load (with('tasks', ...)), por isso o tipo
     * é HasMany e não Builder.
     */
    private function applyFilters(HasMany $query, Request $request): void
    {
        // "Minhas tarefas": assigned_to=me evita o front-end precisar
        // conhecer o próprio id para montar o filtro.
        if ($request->filled('assigned_to')) {
            $assignedTo = $request->input('assigned_to');

            $query->where(
                'assigned_to',
                $assignedTo === 'me' ? $request->user()->id : (int) $assignedTo
            );
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->input('client_id'));
        }

        // Origem é derivada das FKs (não existe coluna source_type), então o
        // filtro também é expresso em termos delas.
        if ($request->filled('origin')) {
            match ($request->input('origin')) {
                KanbanTaskOrigin::Appointment->value => $query->whereNotNull('appointment_id'),
                KanbanTaskOrigin::Order->value => $query->whereNotNull('order_id'),
                KanbanTaskOrigin::Manual->value => $query->whereNull('appointment_id')->whereNull('order_id'),
                default => null,
            };
        }
    }
}
