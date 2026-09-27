<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Autenticação ("quem é o usuário", auth:sanctum) já é implicitamente
 * testada aqui pelos casos de 401. O foco desta classe é autorização ("o
 * que esse usuário pode fazer", middleware "role") — a regra real vive no
 * back-end, então cada caso bate direto no endpoint HTTP, sem atalho.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->getJson('/api/clients')->assertStatus(401);
    }

    public function test_photographer_can_read_clients_but_not_create_one(): void
    {
        $this->actingAsRole('photographer');

        $this->getJson('/api/clients')->assertStatus(200);
        $this->postJson('/api/clients', ['name' => 'Cliente Teste'])->assertStatus(403);
    }

    public function test_editor_cannot_read_clients_or_appointments(): void
    {
        $this->actingAsRole('editor');

        $this->getJson('/api/clients')->assertStatus(403);
        $this->getJson('/api/appointments')->assertStatus(403);
    }

    public function test_attendant_can_manage_clients_and_appointments(): void
    {
        $this->actingAsRole('attendant');

        $this->postJson('/api/clients', ['name' => 'Cliente Teste'])->assertStatus(201);

        $client = Client::factory()->create();
        $this->postJson('/api/appointments', [
            'client_id' => $client->id,
            'title' => 'Sessão de fotos',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])->assertStatus(201);
    }

    public function test_all_four_roles_can_read_orders_but_only_admin_and_attendant_can_create(): void
    {
        $order = Order::factory()->create();

        foreach (['admin', 'attendant', 'photographer', 'editor'] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/orders/{$order->id}")->assertStatus(200);
        }

        $client = Client::factory()->create();

        $this->actingAsRole('photographer');
        $this->postJson('/api/orders', ['client_id' => $client->id, 'title' => 'Pedido novo'])->assertStatus(403);

        $this->actingAsRole('attendant');
        $this->postJson('/api/orders', ['client_id' => $client->id, 'title' => 'Pedido novo'])->assertStatus(201);
    }

    public function test_only_admin_can_delete_an_order(): void
    {
        $order = Order::factory()->create();
        $this->actingAsRole('attendant');
        $this->deleteJson("/api/orders/{$order->id}")->assertStatus(403);

        $this->actingAsRole('admin');
        $this->deleteJson("/api/orders/{$order->id}")->assertStatus(204);
    }

    public function test_photographer_and_editor_can_update_order_status(): void
    {
        foreach (['photographer', 'editor'] as $role) {
            $order = Order::factory()->create();
            $this->actingAsRole($role);

            $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'in_review'])
                ->assertStatus(200)
                ->assertJsonPath('data.status', 'in_review');
        }
    }

    public function test_all_four_roles_can_read_galleries_but_only_admin_can_manage_them(): void
    {
        $gallery = Gallery::factory()->create();

        foreach (['admin', 'attendant', 'photographer', 'editor'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/galleries')->assertStatus(200);
        }

        $client = Client::factory()->create();

        $this->actingAsRole('attendant');
        $this->postJson('/api/galleries', ['client_id' => $client->id, 'title' => 'Galeria nova'])->assertStatus(403);
        $this->patchJson("/api/galleries/{$gallery->id}/close")->assertStatus(403);

        $this->actingAsRole('admin');
        $this->postJson('/api/galleries', ['client_id' => $client->id, 'title' => 'Galeria nova'])->assertStatus(201);
        $this->patchJson("/api/galleries/{$gallery->id}/close")->assertStatus(200);
    }

    public function test_dashboard_endpoints_are_restricted_to_admin_and_attendant(): void
    {
        $this->actingAsRole('photographer');
        $this->getJson('/api/dashboard/summary')->assertStatus(403);
        $this->getJson('/api/dashboard/charts')->assertStatus(403);

        $this->actingAsRole('editor');
        $this->getJson('/api/dashboard/summary')->assertStatus(403);

        $this->actingAsRole('attendant');
        $this->getJson('/api/dashboard/summary')->assertStatus(200);
        $this->getJson('/api/dashboard/charts')->assertStatus(200);
    }

    public function test_appointment_index_respects_role_matrix(): void
    {
        Appointment::factory()->create();

        $this->actingAsRole('photographer');
        $this->getJson('/api/appointments')->assertStatus(200);

        $this->actingAsRole('editor');
        $this->getJson('/api/appointments')->assertStatus(403);
    }
}
