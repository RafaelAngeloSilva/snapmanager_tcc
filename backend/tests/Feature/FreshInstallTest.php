<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\KanbanColumn;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Instalação nova (produção sem os dados de demonstração): o sistema precisa
 * funcionar sem o seeder — quadro com colunas e um caminho para criar o
 * primeiro administrador.
 */
class FreshInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_install_has_the_default_production_columns(): void
    {
        $this->assertSame(
            ['A fazer', 'Em andamento', 'Em revisão', 'Concluído'],
            KanbanColumn::orderBy('position')->pluck('name')->all(),
        );
        $this->assertSame(['Concluído'], KanbanColumn::where('is_done', true)->pluck('name')->all());
    }

    public function test_an_order_can_be_sent_to_production_right_after_install(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'attendant']));
        $order = Order::factory()->create();

        $this->postJson("/api/orders/{$order->id}/kanban")
            ->assertCreated()
            ->assertJsonPath('data.kanban_column_id', KanbanColumn::firstWhere('name', 'A fazer')->id);

        $this->postJson("/api/orders/{$order->id}/kanban")
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_on_board');
    }

    public function test_a_board_without_columns_says_so_instead_of_already_on_board(): void
    {
        KanbanColumn::query()->delete();
        Sanctum::actingAs(User::factory()->create(['role' => 'attendant']));
        $order = Order::factory()->create();

        $this->postJson("/api/orders/{$order->id}/kanban")
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_columns')
            ->assertJsonMissingPath('data');
    }

    public function test_the_first_admin_is_promoted_by_command(): void
    {
        $user = User::factory()->create(['email' => 'Dono@Estudio.test', 'role' => 'attendant']);

        $this->artisan('users:set-role', ['email' => 'dono@estudio.test', 'role' => 'admin'])
            ->expectsOutputToContain('attendant → admin')
            ->assertSuccessful();

        $this->assertSame(UserRole::Admin, $user->fresh()->role);

        Sanctum::actingAs($user->fresh());
        $this->postJson('/api/kanban/columns', ['name' => 'Entrega'])->assertCreated();
    }

    public function test_set_role_rejects_unknown_users_and_roles(): void
    {
        User::factory()->create(['email' => 'ana@estudio.test', 'role' => 'attendant']);

        $this->artisan('users:set-role', ['email' => 'ninguem@estudio.test', 'role' => 'admin'])->assertFailed();
        $this->artisan('users:set-role', ['email' => 'ana@estudio.test', 'role' => 'superuser'])->assertFailed();

        $this->assertSame(UserRole::Attendant, User::firstWhere('email', 'ana@estudio.test')->role);
    }
}
