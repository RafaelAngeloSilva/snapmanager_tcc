<?php

namespace App\Services;

use App\Models\KanbanColumn;
use App\Models\KanbanTask;
use Illuminate\Support\Facades\DB;

/**
 * Regras de posicionamento e conclusão do quadro de produção, fora dos
 * controllers porque são compartilhadas por várias ações (criar card,
 * mover card, importar agendamento/pedido, excluir coluna realocando
 * tarefas).
 *
 * Estratégia de ordenação: inteiros sequenciais (0,1,2,...) normalizados
 * após cada movimentação. Simples de entender e de depurar — nada de
 * "position" fracionária crescendo indefinidamente. Como uma coluna de
 * quadro tem dezenas de cards (não milhares), reescrever a coluna inteira
 * numa transação é barato.
 */
class KanbanService
{
    /**
     * Coluna onde novos cards entram por padrão (a primeira do quadro).
     */
    public function defaultColumn(): ?KanbanColumn
    {
        return KanbanColumn::orderBy('position')->orderBy('id')->first();
    }

    /**
     * Próxima posição livre no fim da coluna.
     */
    public function nextPosition(int $columnId): int
    {
        return (int) KanbanTask::where('kanban_column_id', $columnId)->max('position') + 1;
    }

    /**
     * Move o card para a coluna/posição indicadas e renumera as colunas
     * afetadas. Tudo numa transação: ou o quadro inteiro fica consistente,
     * ou nada muda.
     */
    public function moveTask(KanbanTask $task, KanbanColumn $targetColumn, int $position): KanbanTask
    {
        return DB::transaction(function () use ($task, $targetColumn, $position) {
            $sourceColumnId = $task->kanban_column_id;

            $task->kanban_column_id = $targetColumn->id;
            $this->applyCompletion($task, $targetColumn);
            $task->save();

            $this->reorderColumn($targetColumn->id, $task->id, $position);

            if ($sourceColumnId !== $targetColumn->id) {
                $this->reorderColumn($sourceColumnId);
            }

            return $task->refresh();
        });
    }

    /**
     * Conclusão é uma propriedade do QUADRO, não do pedido: entrar numa
     * coluna marcada como is_done carimba completed_at; sair dela limpa.
     * O status do pedido (OrderStatus) segue seu próprio fluxo e não é
     * tocado aqui — são regras relacionadas, mas separadas.
     */
    public function applyCompletion(KanbanTask $task, KanbanColumn $column): void
    {
        if ($column->is_done) {
            $task->completed_at ??= now();

            return;
        }

        $task->completed_at = null;
    }

    /**
     * Renumera as posições de uma coluna para 0,1,2,... Se $movedTaskId for
     * informado, esse card é reinserido no índice $targetPosition.
     */
    public function reorderColumn(int $columnId, ?int $movedTaskId = null, ?int $targetPosition = null): void
    {
        $ids = KanbanTask::where('kanban_column_id', $columnId)
            ->when($movedTaskId, fn ($query) => $query->whereKeyNot($movedTaskId))
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($movedTaskId !== null) {
            $index = max(0, min($targetPosition ?? count($ids), count($ids)));
            array_splice($ids, $index, 0, [$movedTaskId]);
        }

        if ($ids === []) {
            return;
        }

        // UM único UPDATE com CASE para a coluna inteira, em vez de um UPDATE
        // por card (antes: 30 cards na coluna = 30 queries a cada arraste).
        // Ids e posições são inteiros (cast explícito), então podem entrar
        // direto na expressão sem risco de injeção.
        $cases = collect($ids)
            ->map(fn ($id, $position) => 'WHEN '.(int) $id.' THEN '.(int) $position)
            ->implode(' ');

        KanbanTask::whereKey($ids)->update([
            'position' => DB::raw("CASE id {$cases} END"),
        ]);
    }

    /**
     * Usado ao excluir uma coluna: joga os cards para o fim da coluna de
     * destino, preservando a ordem relativa que tinham.
     */
    public function moveAllTasks(KanbanColumn $from, KanbanColumn $to): void
    {
        DB::transaction(function () use ($from, $to) {
            $tasks = $from->tasks()->orderBy('position')->orderBy('id')->get();
            $position = $this->nextPosition($to->id);

            foreach ($tasks as $task) {
                $task->kanban_column_id = $to->id;
                $task->position = $position++;
                $this->applyCompletion($task, $to);
                $task->save();
            }

            $this->reorderColumn($to->id);
        });
    }
}
