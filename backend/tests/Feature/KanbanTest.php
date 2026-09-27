<?php

namespace Tests\Feature;

use App\Enums\KanbanPriority;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\KanbanColumn;
use App\Models\KanbanTask;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KanbanTest extends TestCase
{
    use RefreshDatabase;

    private KanbanColumn $todo;

    private KanbanColumn $doing;

    private KanbanColumn $done;

    protected function setUp(): void
    {
        parent::setUp();

        $this->todo = KanbanColumn::create(['name' => 'A fazer', 'position' => 0]);
        $this->doing = KanbanColumn::create(['name' => 'Em andamento', 'position' => 1]);
        $this->done = KanbanColumn::create(['name' => 'Concluído', 'position' => 2, 'is_done' => true]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeTask(array $attributes = []): KanbanTask
    {
        return KanbanTask::create([
            'kanban_column_id' => $this->todo->id,
            'title' => 'Tarefa',
            'priority' => KanbanPriority::Medium->value,
            'position' => 0,
            ...$attributes,
        ]);
    }

    // ---------- Autenticação / autorização ----------

    public function test_board_requires_authentication(): void
    {
        $this->getJson('/api/kanban')->assertStatus(401);
    }

    public function test_all_four_roles_can_see_the_board(): void
    {
        foreach (['admin', 'attendant', 'photographer', 'editor'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/kanban')->assertStatus(200);
        }
    }

    public function test_only_admin_can_manage_columns(): void
    {
        $this->actingAsRole('attendant');
        $this->postJson('/api/kanban/columns', ['name' => 'Nova'])->assertStatus(403);
        $this->putJson("/api/kanban/columns/{$this->todo->id}", ['name' => 'X'])->assertStatus(403);
        $this->deleteJson("/api/kanban/columns/{$this->todo->id}")->assertStatus(403);

        $this->actingAsRole('admin');
        $this->postJson('/api/kanban/columns', ['name' => 'Nova'])->assertStatus(201);
        $this->putJson("/api/kanban/columns/{$this->todo->id}", ['name' => 'Renomeada'])->assertStatus(200);

        $this->assertDatabaseHas('kanban_columns', ['id' => $this->todo->id, 'name' => 'Renomeada']);
    }

    public function test_photographer_can_only_move_tasks_assigned_to_them(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $otherTask = $this->makeTask(['title' => 'De outra pessoa']);
        $ownTask = $this->makeTask(['title' => 'Minha', 'assigned_to' => $photographer->id, 'position' => 1]);

        Sanctum::actingAs($photographer);

        $this->patchJson("/api/kanban/tasks/{$otherTask->id}/move", [
            'kanban_column_id' => $this->doing->id,
            'position' => 0,
        ])->assertStatus(403);

        $this->patchJson("/api/kanban/tasks/{$ownTask->id}/move", [
            'kanban_column_id' => $this->doing->id,
            'position' => 0,
        ])->assertStatus(200);
    }

    // ---------- Colunas ----------

    public function test_column_reorder_persists_positions(): void
    {
        $this->actingAsRole('admin');

        $this->patchJson('/api/kanban/columns/reorder', [
            'columns' => [$this->done->id, $this->todo->id, $this->doing->id],
        ])->assertStatus(200);

        $this->assertSame(0, $this->done->refresh()->position);
        $this->assertSame(1, $this->todo->refresh()->position);
        $this->assertSame(2, $this->doing->refresh()->position);
    }

    public function test_column_with_tasks_cannot_be_deleted_without_a_destination(): void
    {
        $this->actingAsRole('admin');
        $task = $this->makeTask();

        $this->deleteJson("/api/kanban/columns/{$this->todo->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('move_tasks_to');

        // A tarefa continua lá — nada foi apagado em silêncio.
        $this->assertDatabaseHas('kanban_tasks', ['id' => $task->id, 'kanban_column_id' => $this->todo->id]);
    }

    public function test_deleting_a_column_moves_its_tasks_to_the_chosen_column(): void
    {
        $this->actingAsRole('admin');
        $task = $this->makeTask();

        $this->deleteJson("/api/kanban/columns/{$this->todo->id}", [
            'move_tasks_to' => $this->doing->id,
        ])->assertStatus(204);

        $this->assertDatabaseMissing('kanban_columns', ['id' => $this->todo->id]);
        $this->assertSame($this->doing->id, $task->refresh()->kanban_column_id);
    }

    // ---------- Tarefas manuais ----------

    public function test_manual_task_is_created_with_manual_origin(): void
    {
        $this->actingAsRole('attendant');

        $response = $this->postJson('/api/kanban/tasks', [
            'kanban_column_id' => $this->todo->id,
            'title' => 'Fazer backup dos cartões',
            'priority' => 'urgent',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.origin', 'manual')
            ->assertJsonPath('data.priority', 'urgent');
    }

    public function test_invalid_priority_is_rejected(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/kanban/tasks', [
            'kanban_column_id' => $this->todo->id,
            'title' => 'Tarefa',
            'priority' => 'altissima',
        ])->assertStatus(422)->assertJsonValidationErrors('priority');
    }

    // ---------- Importação de agendamento / pedido ----------

    public function test_appointment_can_be_imported_and_copies_its_data(): void
    {
        $this->actingAsRole('attendant');

        $photographer = User::factory()->create(['role' => 'photographer']);
        $client = Client::factory()->create();
        $appointment = Appointment::factory()->create([
            'client_id' => $client->id,
            'user_id' => $photographer->id,
            'title' => 'Ensaio gestante',
        ]);

        $response = $this->postJson("/api/appointments/{$appointment->id}/kanban");

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Ensaio gestante')
            ->assertJsonPath('data.origin', 'appointment')
            // O profissional do agendamento vira o responsável do card.
            ->assertJsonPath('data.assignee.id', $photographer->id)
            ->assertJsonPath('data.client.id', $client->id);
    }

    public function test_the_same_appointment_cannot_be_imported_twice(): void
    {
        $this->actingAsRole('attendant');
        $appointment = Appointment::factory()->create();

        $this->postJson("/api/appointments/{$appointment->id}/kanban")->assertStatus(201);

        $this->postJson("/api/appointments/{$appointment->id}/kanban")
            ->assertStatus(409)
            // Devolve o card existente para o front oferecer "ver no quadro".
            ->assertJsonPath('data.appointment.id', $appointment->id);

        $this->assertSame(1, KanbanTask::where('appointment_id', $appointment->id)->count());
    }

    public function test_order_can_be_imported_and_cannot_be_duplicated(): void
    {
        $this->actingAsRole('attendant');
        $order = Order::factory()->create(['title' => 'Álbum 30x30', 'due_date' => now()->addDays(5)]);

        $this->postJson("/api/orders/{$order->id}/kanban")
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Álbum 30x30')
            ->assertJsonPath('data.origin', 'order');

        $this->postJson("/api/orders/{$order->id}/kanban")->assertStatus(409);

        $this->assertSame(1, KanbanTask::where('order_id', $order->id)->count());
    }

    public function test_moving_a_card_does_not_change_the_order_status(): void
    {
        $this->actingAsRole('admin');
        $order = Order::factory()->create(['status' => 'in_editing']);

        $this->postJson("/api/orders/{$order->id}/kanban")->assertStatus(201);
        $task = KanbanTask::where('order_id', $order->id)->firstOrFail();

        $this->patchJson("/api/kanban/tasks/{$task->id}/move", [
            'kanban_column_id' => $this->done->id,
            'position' => 0,
        ])->assertStatus(200);

        // Kanban e status do pedido são regras separadas nesta versão.
        $this->assertSame('in_editing', $order->refresh()->status->value);
    }

    // ---------- Movimentação e posição ----------

    public function test_moving_a_task_persists_positions_in_both_columns(): void
    {
        $this->actingAsRole('admin');

        $first = $this->makeTask(['title' => 'A', 'position' => 0]);
        $second = $this->makeTask(['title' => 'B', 'position' => 1]);
        $third = $this->makeTask(['title' => 'C', 'position' => 2]);

        // Move o primeiro card para o meio da outra coluna.
        $target = $this->makeTask(['title' => 'X', 'kanban_column_id' => $this->doing->id, 'position' => 0]);

        $this->patchJson("/api/kanban/tasks/{$first->id}/move", [
            'kanban_column_id' => $this->doing->id,
            'position' => 1,
        ])->assertStatus(200);

        // Coluna de destino renumerada: X (0), A (1).
        $this->assertSame(0, $target->refresh()->position);
        $this->assertSame(1, $first->refresh()->position);
        $this->assertSame($this->doing->id, $first->kanban_column_id);

        // Coluna de origem fecha o buraco: B (0), C (1).
        $this->assertSame(0, $second->refresh()->position);
        $this->assertSame(1, $third->refresh()->position);
    }

    public function test_reordering_inside_the_same_column_persists(): void
    {
        $this->actingAsRole('admin');

        $a = $this->makeTask(['title' => 'A', 'position' => 0]);
        $b = $this->makeTask(['title' => 'B', 'position' => 1]);
        $c = $this->makeTask(['title' => 'C', 'position' => 2]);

        // Joga C para o topo.
        $this->patchJson("/api/kanban/tasks/{$c->id}/move", [
            'kanban_column_id' => $this->todo->id,
            'position' => 0,
        ])->assertStatus(200);

        $this->assertSame(0, $c->refresh()->position);
        $this->assertSame(1, $a->refresh()->position);
        $this->assertSame(2, $b->refresh()->position);
    }

    // ---------- Conclusão ----------

    public function test_entering_a_done_column_stamps_completed_at_and_leaving_clears_it(): void
    {
        $this->actingAsRole('admin');
        $task = $this->makeTask();

        $this->patchJson("/api/kanban/tasks/{$task->id}/move", [
            'kanban_column_id' => $this->done->id,
            'position' => 0,
        ])->assertStatus(200);

        $this->assertNotNull($task->refresh()->completed_at);

        $this->patchJson("/api/kanban/tasks/{$task->id}/move", [
            'kanban_column_id' => $this->todo->id,
            'position' => 0,
        ])->assertStatus(200);

        $this->assertNull($task->refresh()->completed_at);
    }

    // ---------- Filtros ----------

    public function test_my_tasks_filter_only_returns_tasks_assigned_to_the_current_user(): void
    {
        $user = $this->actingAsRole('photographer');

        $this->makeTask(['title' => 'Minha', 'assigned_to' => $user->id]);
        $this->makeTask(['title' => 'De outro', 'position' => 1]);

        $response = $this->getJson('/api/kanban?assigned_to=me')->assertStatus(200);

        $titles = collect($response->json('data'))->flatMap(fn ($column) => $column['tasks'])->pluck('title');

        $this->assertEqualsCanonicalizing(['Minha'], $titles->all());
    }

    public function test_origin_and_priority_filters_work(): void
    {
        $this->actingAsRole('admin');

        $appointment = Appointment::factory()->create();
        $this->postJson("/api/appointments/{$appointment->id}/kanban")->assertStatus(201);
        $this->makeTask(['title' => 'Manual urgente', 'priority' => 'urgent', 'position' => 5]);

        $manualOnly = $this->getJson('/api/kanban?origin=manual')->assertStatus(200);
        $manualTitles = collect($manualOnly->json('data'))->flatMap(fn ($c) => $c['tasks'])->pluck('title');
        $this->assertEqualsCanonicalizing(['Manual urgente'], $manualTitles->all());

        $appointmentOnly = $this->getJson('/api/kanban?origin=appointment')->assertStatus(200);
        $appointmentTitles = collect($appointmentOnly->json('data'))->flatMap(fn ($c) => $c['tasks'])->pluck('title');
        $this->assertEqualsCanonicalizing([$appointment->title], $appointmentTitles->all());

        $urgentOnly = $this->getJson('/api/kanban?priority=urgent')->assertStatus(200);
        $urgentTitles = collect($urgentOnly->json('data'))->flatMap(fn ($c) => $c['tasks'])->pluck('title');
        $this->assertEqualsCanonicalizing(['Manual urgente'], $urgentTitles->all());
    }

    // ---------- Vínculos ----------

    public function test_task_links_cannot_be_rewired_through_update(): void
    {
        $this->actingAsRole('admin');
        $appointment = Appointment::factory()->create();
        $other = Appointment::factory()->create();

        $this->postJson("/api/appointments/{$appointment->id}/kanban")->assertStatus(201);
        $task = KanbanTask::where('appointment_id', $appointment->id)->firstOrFail();

        $this->putJson("/api/kanban/tasks/{$task->id}", [
            'title' => 'Novo título',
            'appointment_id' => $other->id,
        ])->assertStatus(200);

        // Título muda; vínculo de origem permanece.
        $task->refresh();
        $this->assertSame('Novo título', $task->title);
        $this->assertSame($appointment->id, $task->appointment_id);
    }
}
