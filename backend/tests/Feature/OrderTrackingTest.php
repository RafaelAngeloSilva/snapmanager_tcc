<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Acompanhe seu Pedido": link público de acompanhamento por token.
 */
class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::factory()->create(['name' => 'Maria Souza', 'phone' => '(11) 98765-4321']);
        $this->order = Order::factory()->create([
            'client_id' => $client->id,
            'title' => 'Ensaio gestante',
            'status' => OrderStatus::InEditing->value,
            'total' => 1500,
            'notes' => 'Observação interna sigilosa',
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Regressão do HTTP 500 em POST /orders/{order}/tracking:
     * "SQLSTATE[42703]: Undefined column: tracking_token" — o código subiu
     * sem a migration 2026_09_26_100000_add_tracking_to_orders_table ter
     * sido executada. Garante que as migrations criam TODAS as colunas que
     * o Order usa no acompanhamento.
     */
    public function test_orders_table_has_every_tracking_column_used_by_the_model(): void
    {
        $this->assertTrue(Schema::hasColumns('orders', [
            'tracking_token',
            'tracking_enabled_at',
            'tracking_last_viewed_at',
            'tracking_views',
        ]));
    }

    public function test_enable_returns_success_and_persists_token_for_valid_order(): void
    {
        $this->actingAsRole('attendant');

        $token = $this->postJson("/api/orders/{$this->order->id}/tracking")
            ->assertCreated()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.views', 0)
            ->json('data.token');

        $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'tracking_token' => $token]);
        $this->assertNotNull($this->order->fresh()->tracking_enabled_at);

        // O token devolvido é exatamente o que abre o link público
        // (/acompanhar/{token} no front → GET /api/public/tracking/{token}).
        $this->getJson("/api/public/tracking/{$token}?preview=1")
            ->assertOk()
            ->assertJsonPath('data.title', 'Ensaio gestante');
    }

    public function test_enable_for_nonexistent_order_returns_404(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/orders/999999/tracking')->assertNotFound();
        $this->deleteJson('/api/orders/999999/tracking')->assertNotFound();
    }

    public function test_guest_cannot_enable_tracking(): void
    {
        $this->postJson("/api/orders/{$this->order->id}/tracking")->assertUnauthorized();
        $this->assertNull($this->order->fresh()->tracking_token);
    }

    public function test_dashboard_alerts_keep_working_with_tracking_columns(): void
    {
        // O mesmo erro de coluna ausente também derrubava GET /dashboard/alerts.
        $this->actingAsRole('admin');

        $this->getJson('/api/dashboard/alerts')->assertOk();
    }

    public function test_attendant_can_enable_tracking_and_token_is_unique_per_order(): void
    {
        $this->actingAsRole('attendant');

        $response = $this->postJson("/api/orders/{$this->order->id}/tracking")->assertCreated();

        $token = $response->json('data.token');
        $this->assertTrue($response->json('data.enabled'));
        $this->assertSame(40, strlen($token));

        $other = Order::factory()->create();
        $otherToken = $this->postJson("/api/orders/{$other->id}/tracking")->json('data.token');

        $this->assertNotSame($token, $otherToken);
    }

    public function test_enabling_twice_keeps_the_same_link(): void
    {
        $this->actingAsRole('admin');

        $first = $this->postJson("/api/orders/{$this->order->id}/tracking")->json('data.token');
        $second = $this->postJson("/api/orders/{$this->order->id}/tracking")->assertOk()->json('data.token');

        $this->assertSame($first, $second);
    }

    public function test_photographer_and_editor_cannot_manage_tracking_nor_see_token(): void
    {
        $this->order->enableTracking();

        foreach (['photographer', 'editor'] as $role) {
            $this->actingAsRole($role);

            $this->postJson("/api/orders/{$this->order->id}/tracking")->assertForbidden();
            $this->deleteJson("/api/orders/{$this->order->id}/tracking")->assertForbidden();
            $this->getJson("/api/orders/{$this->order->id}")->assertOk()->assertJsonMissingPath('data.tracking');
        }
    }

    public function test_order_details_expose_tracking_state_to_attendant(): void
    {
        $this->actingAsRole('attendant');

        $this->getJson("/api/orders/{$this->order->id}")
            ->assertOk()
            ->assertJsonPath('data.tracking.enabled', false)
            ->assertJsonPath('data.tracking.token', null);
    }

    public function test_public_page_shows_progress_without_internal_data(): void
    {
        $this->order->enableTracking();

        $response = $this->getJson("/api/public/tracking/{$this->order->tracking_token}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Ensaio gestante')
            ->assertJsonPath('data.status', 'in_editing')
            ->assertJsonPath('data.client_first_name', 'Maria')
            ->assertJsonPath('data.steps.2.status', 'in_editing')
            ->assertJsonPath('data.steps.2.state', 'current')
            ->assertJsonPath('data.steps.0.state', 'done')
            ->assertJsonPath('data.steps.5.state', 'upcoming');

        $json = json_encode($response->json());
        $this->assertStringNotContainsString('sigilosa', $json);
        $this->assertStringNotContainsString('1500', $json);
        $this->assertStringNotContainsString('98765', $json);
        $this->assertArrayNotHasKey('id', $response->json('data'));
    }

    public function test_client_visit_is_counted_but_staff_preview_is_not(): void
    {
        $this->order->enableTracking();
        $token = $this->order->tracking_token;

        $this->getJson("/api/public/tracking/{$token}?preview=1")->assertOk();
        $this->assertSame(0, $this->order->fresh()->tracking_views);

        $this->getJson("/api/public/tracking/{$token}")->assertOk();
        $this->assertSame(1, $this->order->fresh()->tracking_views);
        $this->assertNotNull($this->order->fresh()->tracking_last_viewed_at);
    }

    public function test_revoked_link_stops_working_and_reactivation_generates_new_token(): void
    {
        $this->actingAsRole('attendant');

        $old = $this->postJson("/api/orders/{$this->order->id}/tracking")->json('data.token');

        $this->deleteJson("/api/orders/{$this->order->id}/tracking")
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->getJson("/api/public/tracking/{$old}")->assertNotFound();

        $new = $this->postJson("/api/orders/{$this->order->id}/tracking")->json('data.token');
        $this->assertNotSame($old, $new);
        $this->getJson("/api/public/tracking/{$new}")->assertOk();
    }

    public function test_unknown_token_and_deleted_order_return_404(): void
    {
        $this->getJson('/api/public/tracking/'.str_repeat('a', 40))->assertNotFound();

        $this->order->enableTracking();
        $token = $this->order->tracking_token;
        $this->order->delete();

        $this->getJson("/api/public/tracking/{$token}")->assertNotFound();
    }

    public function test_client_visit_creates_dashboard_alert(): void
    {
        $this->order->enableTracking();
        $this->getJson("/api/public/tracking/{$this->order->tracking_token}")->assertOk();

        $this->actingAsRole('admin');

        $this->getJson('/api/dashboard/alerts?type=tracking_viewed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entity_type', 'order')
            ->assertJsonPath('data.0.entity_id', $this->order->id);
    }

    public function test_cancelled_order_is_reported_as_cancelled(): void
    {
        $this->order->update(['status' => OrderStatus::Cancelled]);
        $this->order->enableTracking();

        $this->getJson("/api/public/tracking/{$this->order->tracking_token}")
            ->assertOk()
            ->assertJsonPath('data.is_cancelled', true)
            ->assertJsonPath('data.status_label', 'Cancelado');
    }
}
