<?php

namespace Database\Seeders;

use App\Enums\KanbanPriority;
use App\Models\Appointment;
use App\Models\KanbanColumn;
use App\Models\KanbanTask;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Quadro de exemplo: as 5 etapas típicas de um estúdio, com um card de cada
 * origem (agendamento, pedido e demanda manual) para a demonstração.
 */
class KanbanSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@snapmanager.test')->first();
        $photographer = User::where('email', 'fotografo@snapmanager.test')->first();
        $editor = User::where('email', 'editor@snapmanager.test')->first();

        // A migration de colunas padrão já criou um quadro básico; a
        // demonstração usa o próprio conjunto de colunas no lugar dele.
        KanbanColumn::query()->delete();

        $columns = collect(['Agendado', 'A fazer', 'Em andamento', 'Em revisão', 'Concluído'])
            ->map(fn (string $name, int $index) => KanbanColumn::create([
                'name' => $name,
                'position' => $index,
                // Só a última coluna marca conclusão da tarefa.
                'is_done' => $name === 'Concluído',
                'created_by' => $admin?->id,
            ]));

        $agendado = $columns->firstWhere('name', 'Agendado');
        $emAndamento = $columns->firstWhere('name', 'Em andamento');
        $aFazer = $columns->firstWhere('name', 'A fazer');

        // 1) Card vindo de um agendamento existente.
        $appointment = Appointment::orderBy('starts_at')->first();

        if ($appointment) {
            KanbanTask::create([
                'kanban_column_id' => $agendado->id,
                'title' => $appointment->title,
                'description' => $appointment->session_type ? "Sessão: {$appointment->session_type}" : null,
                'assigned_to' => $appointment->user_id ?? $photographer?->id,
                'priority' => KanbanPriority::Medium->value,
                'due_date' => $appointment->starts_at?->toDateString(),
                'position' => 0,
                'client_id' => $appointment->client_id,
                'appointment_id' => $appointment->id,
                'created_by' => $admin?->id,
            ]);
        }

        // 2) Card vindo de um pedido existente.
        $order = Order::whereNotNull('due_date')->orderBy('due_date')->first();

        if ($order) {
            KanbanTask::create([
                'kanban_column_id' => $emAndamento->id,
                'title' => $order->title,
                'description' => $order->service_type ? "Serviço: {$order->service_type}" : null,
                'assigned_to' => $editor?->id,
                'priority' => KanbanPriority::High->value,
                'due_date' => $order->due_date?->toDateString(),
                'position' => 0,
                'client_id' => $order->client_id,
                'order_id' => $order->id,
                'created_by' => $admin?->id,
            ]);
        }

        // 3) Demanda manual, sem vínculo com nenhum registro.
        KanbanTask::create([
            'kanban_column_id' => $aFazer->id,
            'title' => 'Backup dos cartões do fim de semana',
            'description' => 'Copiar para o HD externo e conferir integridade dos arquivos.',
            'assigned_to' => $photographer?->id,
            'priority' => KanbanPriority::Urgent->value,
            'due_date' => now()->addDays(2)->toDateString(),
            'position' => 0,
            'created_by' => $admin?->id,
        ]);
    }
}
