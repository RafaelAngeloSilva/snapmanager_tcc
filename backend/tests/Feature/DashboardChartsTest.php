<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardChartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_charts_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/charts')->assertStatus(401);
    }

    public function test_charts_endpoint_does_not_break_with_no_data(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/dashboard/charts');

        $response->assertStatus(200)
            ->assertJsonCount(7, 'orders_by_status')
            ->assertJsonCount(30, 'revenue_by_period')
            ->assertJsonCount(7, 'appointments_by_period');

        foreach ($response->json('orders_by_status') as $entry) {
            $this->assertSame(0, $entry['count']);
        }
    }

    public function test_orders_by_status_aggregates_real_data_with_labels(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        Order::factory()->create(['status' => OrderStatus::Delivered->value, 'total' => 500]);
        Order::factory()->create(['status' => OrderStatus::Delivered->value, 'total' => 300]);

        $response = $this->getJson('/api/dashboard/charts');
        $response->assertStatus(200);

        $deliveredEntry = collect($response->json('orders_by_status'))
            ->firstWhere('status', 'delivered');

        $this->assertSame(2, $deliveredEntry['count']);
        $this->assertSame('Entregue', $deliveredEntry['label']);

        // Faturamento entra no dia de hoje (created_at é usado como proxy da
        // data de entrega, já que não existe uma coluna dedicada — mesma
        // decisão documentada no relatório em PDF do Dashboard).
        $todayRevenue = collect($response->json('revenue_by_period'))->last();
        $this->assertEquals(800.0, $todayRevenue['total']);
    }

    public function test_appointments_by_period_counts_only_upcoming_and_non_cancelled(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        Appointment::factory()->create(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(), 'status' => 'scheduled']);
        Appointment::factory()->create(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(), 'status' => 'cancelled']);
        Appointment::factory()->create(['starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHour(), 'status' => 'scheduled']);

        $response = $this->getJson('/api/dashboard/charts');
        $response->assertStatus(200);

        $totalInWindow = collect($response->json('appointments_by_period'))->sum('count');

        // Só o agendamento "scheduled" dentro dos próximos 7 dias entra —
        // o cancelado e o que está fora da janela de 7 dias, não.
        $this->assertSame(1, $totalInWindow);
    }
}
