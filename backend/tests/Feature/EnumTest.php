<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnumTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_valid_order_status_is_accepted_and_persisted_as_plain_string(): void
    {
        $this->actingAsAdmin();
        $order = Order::factory()->create();

        $response = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'delivered']);

        $response->assertStatus(200)->assertJsonPath('data.status', 'delivered');

        // O banco continua guardando varchar puro, sem tipo ENUM nativo do
        // PostgreSQL — só a aplicação (Model::casts) sabe que é um enum.
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'delivered']);
    }

    public function test_invalid_order_status_is_rejected_with_422(): void
    {
        $this->actingAsAdmin();
        $order = Order::factory()->create();

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'not_a_real_status'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_invalid_appointment_status_is_rejected_with_422(): void
    {
        $this->actingAsAdmin();
        $client = Client::factory()->create();

        $this->postJson('/api/appointments', [
            'client_id' => $client->id,
            'title' => 'Sessão',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'status' => 'not_a_real_status',
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_invalid_gallery_watermark_type_is_rejected_with_422(): void
    {
        $this->actingAsAdmin();
        $client = Client::factory()->create();

        $this->postJson('/api/galleries', [
            'client_id' => $client->id,
            'title' => 'Galeria',
            'watermark_type' => 'not_a_real_type',
        ])->assertStatus(422)->assertJsonValidationErrors('watermark_type');
    }

    public function test_status_history_records_and_returns_the_correct_enum_values(): void
    {
        $admin = $this->actingAsAdmin();
        $order = Order::factory()->create(['status' => OrderStatus::Pending->value]);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'captured'])->assertStatus(200);

        $this->assertDatabaseHas('status_histories', [
            'order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'captured',
            'changed_by' => $admin->id,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");
        $response->assertStatus(200);
        // OrderController::show() retorna um JsonResource "solto" — o
        // Laravel envolve automaticamente em {"data": {...}} por padrão.
        $histories = $response->json('data.status_histories');

        $this->assertSame('pending', $histories[0]['from_status']);
        $this->assertSame('captured', $histories[0]['to_status']);
    }

    public function test_dashboard_summary_counts_orders_by_status_correctly(): void
    {
        $this->actingAsAdmin();

        Order::factory()->create(['status' => OrderStatus::Pending->value]);
        Order::factory()->create(['status' => OrderStatus::Pending->value]);
        Order::factory()->create(['status' => OrderStatus::Delivered->value]);
        Order::factory()->create(['status' => OrderStatus::Cancelled->value]);

        $response = $this->getJson('/api/dashboard/summary');

        $response->assertStatus(200)
            ->assertJsonPath('orders.pending_orders', 2)
            ->assertJsonPath('orders.delivered_orders', 1)
            ->assertJsonPath('orders.cancelled_orders', 1)
            ->assertJsonPath('orders.captured_orders', 0)
            ->assertJsonPath('orders.total_orders', 4);
    }

    public function test_seeders_run_successfully_with_enum_casts_in_place(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', ['role' => 'admin']);
        $this->assertDatabaseHas('appointments', ['status' => 'scheduled']);
        $this->assertGreaterThan(0, Order::count());
    }
}
