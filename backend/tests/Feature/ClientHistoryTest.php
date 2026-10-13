<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\OrderStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\ClientHistoryEntry;
use App\Models\Document;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\StatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create(['name' => 'Mariana Souza']);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function shoot(string $status, string $startsAt, array $extra = []): Appointment
    {
        return Appointment::factory()->create([
            'client_id' => $this->client->id,
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => date('Y-m-d H:i:s', strtotime($startsAt) + 3600),
            ...$extra,
        ]);
    }

    private function completedShoots(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->shoot('completed', now()->subDays($i * 10)->toDateTimeString());
        }
    }

    private function entryPayload(array $overrides = []): array
    {
        return [
            'category' => 'general',
            'title' => 'Prefere luz natural',
            'notes' => 'Comentou que ama o fim de tarde.',
            'event_date' => now()->toDateString(),
            ...$overrides,
        ];
    }

    // ============================================================ fidelidade

    /**
     * @return array<string, array{int, string}>
     */
    public static function tiers(): array
    {
        return [
            'sem ensaios' => [0, 'new'],
            '2 ensaios' => [2, 'new'],
            '3 ensaios' => [3, 'loyal'],
            '5 ensaios' => [5, 'loyal'],
            '6 ensaios' => [6, 'vip'],
        ];
    }

    #[DataProvider('tiers')]
    public function test_loyalty_tier_boundaries(int $completed, string $tier): void
    {
        $this->actingAsRole('attendant');
        $this->completedShoots($completed);

        $this->getJson("/api/clients/{$this->client->id}/history")
            ->assertOk()
            ->assertJsonPath('data.summary.completed_shoots', $completed)
            ->assertJsonPath('data.summary.loyalty.tier', $tier);
    }

    public function test_only_completed_past_and_not_deleted_shoots_count(): void
    {
        $this->actingAsRole('attendant');
        $this->completedShoots(2);
        $this->shoot('cancelled', now()->subDays(3)->toDateTimeString());
        $this->shoot('scheduled', now()->subDays(4)->toDateTimeString());   // não foi marcado como realizado
        $this->shoot('confirmed', now()->addDays(5)->toDateTimeString());   // futuro
        $this->shoot('completed', now()->addDays(2)->toDateTimeString());   // "concluído" antes da hora
        $this->shoot('completed', now()->subDays(50)->toDateTimeString())->delete(); // excluído

        $this->getJson("/api/clients/{$this->client->id}/history")
            ->assertOk()
            ->assertJsonPath('data.summary.completed_shoots', 2)
            ->assertJsonPath('data.summary.loyalty.tier', 'new');
    }

    public function test_summary_shows_client_since_last_and_next_shoot(): void
    {
        $this->actingAsRole('attendant');
        $last = $this->shoot('completed', '2026-09-01 14:00:00');
        $this->shoot('completed', '2026-06-01 14:00:00');
        $next = $this->shoot('confirmed', now()->addDays(12)->setTime(15, 0)->toDateTimeString());
        $this->shoot('scheduled', now()->addDays(40)->toDateTimeString());
        $this->shoot('cancelled', now()->addDays(3)->toDateTimeString());

        $summary = $this->getJson("/api/clients/{$this->client->id}/history")->assertOk()->json('data.summary');

        $this->assertSame($this->client->created_at->toIso8601String(), $summary['client_since']);
        $this->assertSame($last->starts_at->toIso8601String(), $summary['last_shoot_at']);
        $this->assertSame($next->starts_at->toIso8601String(), $summary['next_shoot_at']);
    }

    // ============================================================ timeline

    public function test_timeline_derives_events_from_existing_modules_newest_first(): void
    {
        $this->actingAsRole('admin');
        $shoot = $this->shoot('completed', now()->subDays(5)->toDateTimeString(), ['title' => 'Pré-Wedding']);
        $this->shoot('cancelled', now()->addDays(5)->toDateTimeString(), ['title' => 'Família']);

        $order = Order::factory()->create(['client_id' => $this->client->id, 'appointment_id' => $shoot->id, 'title' => 'Álbum 30x30', 'total' => 450, 'status' => OrderStatus::Delivered->value]);
        StatusHistory::create(['order_id' => $order->id, 'from_status' => 'ready_for_delivery', 'to_status' => 'delivered']);

        Document::withoutEvents(fn () => Document::forceCreate([
            'client_id' => $this->client->id, 'appointment_id' => $shoot->id, 'title' => 'Contrato Pré-Wedding', 'type' => 'photoshoot', 'status' => 'signed',
            'original_file_path' => 'documents/c.pdf', 'original_file_name' => 'c.pdf', 'original_file_size' => 10,
            'sent_at' => now()->subDays(8), 'signed_at' => now()->subDays(7),
        ]));

        Gallery::factory()->create(['client_id' => $this->client->id, 'title' => 'Fotos Pré-Wedding', 'selection_completed_at' => now()->subDay()]);

        $timeline = $this->getJson("/api/clients/{$this->client->id}/history")->assertOk()->json('data.timeline');
        $types = array_column($timeline, 'type');

        foreach (['client_registered', 'shoot_scheduled', 'shoot_completed', 'shoot_cancelled', 'order_created', 'order_delivered', 'contract_created', 'contract_sent', 'contract_signed', 'gallery_created', 'gallery_selection_completed'] as $type) {
            $this->assertContains($type, $types, "Evento {$type} ausente");
        }

        $dates = array_column($timeline, 'date');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, 'Mais recente primeiro.');

        $order = collect($timeline)->firstWhere('type', 'order_created');
        $this->assertSame('financial', $order['group']);
        $this->assertStringContainsString('R$ 450,00', $order['description']);
        $this->assertSame('Ensaio Pré-Wedding', $order['appointment']['title']);
        $this->assertNotContains('payment_received', $types, 'Não há módulo de pagamentos: nada de evento inventado.');
        $this->assertTrue(collect($timeline)->every(fn ($e) => $e['source'] === 'auto'));
    }

    public function test_deleted_records_disappear_from_the_timeline(): void
    {
        $this->actingAsRole('admin');
        $this->shoot('completed', now()->subDays(5)->toDateTimeString())->delete();
        Order::factory()->create(['client_id' => $this->client->id])->delete();

        $types = array_column($this->getJson("/api/clients/{$this->client->id}/history")->json('data.timeline'), 'type');

        $this->assertSame(['client_registered'], $types);
    }

    public function test_deleted_client_history_is_not_reachable_and_pending_shoots_were_cancelled(): void
    {
        $this->actingAsRole('admin');
        $shoot = $this->shoot('scheduled', now()->addDays(3)->toDateTimeString());

        $this->deleteJson("/api/clients/{$this->client->id}")->assertNoContent();

        $this->assertSame(AppointmentStatus::Cancelled, $shoot->fresh()->status);
        $this->getJson("/api/clients/{$this->client->id}/history")->assertNotFound();
        $this->postJson("/api/clients/{$this->client->id}/history-entries", $this->entryPayload())->assertNotFound();
    }

    // ============================================================ acesso

    public function test_roles_that_see_clients_can_use_the_history_and_editor_cannot(): void
    {
        foreach (['admin', 'attendant', 'photographer'] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/clients/{$this->client->id}/history")->assertOk();
        }

        $this->actingAsRole('editor');
        $this->getJson("/api/clients/{$this->client->id}/history")->assertForbidden();
        $this->putJson("/api/clients/{$this->client->id}/preferences", ['preference_notes' => 'x'])->assertForbidden();
        $this->postJson("/api/clients/{$this->client->id}/history-entries", $this->entryPayload())->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson("/api/clients/{$this->client->id}/history")->assertUnauthorized();
    }

    // ============================================================ preferências

    public function test_photographer_saves_preference_notes_with_limits(): void
    {
        $this->actingAsRole('photographer');

        $this->putJson("/api/clients/{$this->client->id}/preferences", ['preference_notes' => "  Prefere fotos espontâneas.\nPerfil esquerdo.  "])
            ->assertOk()
            ->assertJsonPath('data.preference_notes', "Prefere fotos espontâneas.\nPerfil esquerdo.");

        $this->putJson("/api/clients/{$this->client->id}/preferences", ['preference_notes' => str_repeat('a', 1501)])
            ->assertUnprocessable()->assertJsonValidationErrors('preference_notes');

        $this->putJson("/api/clients/{$this->client->id}/preferences", ['preference_notes' => 'Oi <script>alert(1)</script>'])
            ->assertUnprocessable()->assertJsonValidationErrors('preference_notes');

        $this->putJson("/api/clients/{$this->client->id}/preferences", ['preference_notes' => ['array']])
            ->assertUnprocessable();

        $this->putJson("/api/clients/{$this->client->id}/preferences", ['preference_notes' => '   '])
            ->assertOk()->assertJsonPath('data.preference_notes', null);

        $this->assertNull($this->client->fresh()->preference_notes);
    }

    public function test_regular_client_update_does_not_touch_preference_notes(): void
    {
        $this->client->forceFill(['preference_notes' => 'Gosta de pôr do sol.'])->save();
        $this->actingAsRole('attendant');

        $this->putJson("/api/clients/{$this->client->id}", ['name' => 'Mariana S.', 'preference_notes' => 'hackeado'])->assertOk();

        $this->assertSame('Gosta de pôr do sol.', $this->client->fresh()->preference_notes);
    }

    // ============================================================ anotações

    public function test_entry_is_created_for_the_route_client_by_the_current_user(): void
    {
        $user = $this->actingAsRole('photographer');
        $other = Client::factory()->create();
        $shoot = $this->shoot('completed', now()->subDay()->toDateTimeString(), ['title' => 'Família']);

        $response = $this->postJson("/api/clients/{$this->client->id}/history-entries", $this->entryPayload([
            'client_id' => $other->id,       // ignorado
            'user_id' => 999,                // ignorado
            'appointment_id' => $shoot->id,
        ]))->assertCreated();

        $response->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.group', 'service')
            ->assertJsonPath('data.appointment.title', 'Ensaio Família')
            ->assertJsonPath('data.entry.can_edit', true);

        $entry = ClientHistoryEntry::firstOrFail();
        $this->assertSame($this->client->id, $entry->client_id);
        $this->assertSame($user->id, $entry->user_id);
    }

    public function test_entry_cannot_link_a_shoot_of_another_client(): void
    {
        $this->actingAsRole('attendant');
        $foreign = Appointment::factory()->create(['client_id' => Client::factory()->create()->id]);
        $deleted = $this->shoot('completed', now()->subDay()->toDateTimeString());
        $deleted->delete();

        foreach ([$foreign->id, $deleted->id, 'abc'] as $id) {
            $this->postJson("/api/clients/{$this->client->id}/history-entries", $this->entryPayload(['appointment_id' => $id]))
                ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        }

        $this->assertSame(0, ClientHistoryEntry::count());
    }

    public function test_entry_validation(): void
    {
        $this->actingAsRole('attendant');
        $url = "/api/clients/{$this->client->id}/history-entries";

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['category', 'title', 'event_date']);
        $this->postJson($url, $this->entryPayload(['category' => 'vip']))->assertJsonValidationErrors('category');
        $this->postJson($url, $this->entryPayload(['title' => str_repeat('a', 121)]))->assertJsonValidationErrors('title');
        $this->postJson($url, $this->entryPayload(['title' => '<b>oi</b>']))->assertJsonValidationErrors('title');
        $this->postJson($url, $this->entryPayload(['notes' => str_repeat('a', 2001)]))->assertJsonValidationErrors('notes');
        $this->postJson($url, $this->entryPayload(['notes' => '<img src=x onerror=alert(1)>']))->assertJsonValidationErrors('notes');
        $this->postJson($url, $this->entryPayload(['event_date' => '12/10/2026']))->assertJsonValidationErrors('event_date');
        $this->postJson($url, $this->entryPayload(['event_date' => now()->addYears(2)->toDateString()]))->assertJsonValidationErrors('event_date');
        $this->postJson($url, $this->entryPayload(['title' => ['x']]))->assertJsonValidationErrors('title');

        // "<3" e "a < b" não são HTML.
        $this->postJson($url, $this->entryPayload(['title' => 'Cliente amou <3', 'notes' => 'nota 5 < 10']))->assertCreated();
    }

    public function test_testimonial_flag_only_applies_to_post_sale(): void
    {
        $this->actingAsRole('attendant');
        $url = "/api/clients/{$this->client->id}/history-entries";

        $this->postJson($url, $this->entryPayload(['category' => 'general', 'is_testimonial_candidate' => true]))
            ->assertCreated()->assertJsonPath('data.entry.is_testimonial_candidate', false);

        $this->postJson($url, $this->entryPayload(['category' => 'post_sale', 'is_testimonial_candidate' => true]))
            ->assertCreated()
            ->assertJsonPath('data.entry.is_testimonial_candidate', true)
            ->assertJsonPath('data.group', 'post_sale');
    }

    public function test_only_author_or_admin_edits_and_deletes_entries(): void
    {
        $author = $this->actingAsRole('photographer');
        $id = $this->postJson("/api/clients/{$this->client->id}/history-entries", $this->entryPayload())->json('data.entry.id');
        $url = "/api/clients/{$this->client->id}/history-entries/{$id}";

        $this->actingAsRole('attendant');
        $this->getJson("/api/clients/{$this->client->id}/history")->assertJsonPath('data.timeline.0.entry.can_edit', false);
        $this->putJson($url, $this->entryPayload(['title' => 'Alterado']))->assertForbidden();
        $this->deleteJson($url)->assertForbidden();

        Sanctum::actingAs($author);
        $this->putJson($url, $this->entryPayload(['title' => 'Editado pelo autor', 'category' => 'briefing']))
            ->assertOk()->assertJsonPath('data.title', 'Editado pelo autor')->assertJsonPath('data.entry.category', 'briefing');

        $this->actingAsRole('admin');
        $this->deleteJson($url)->assertNoContent();
        $this->assertSame(0, ClientHistoryEntry::count());
    }

    public function test_entry_of_another_client_is_not_reachable_through_this_client(): void
    {
        $this->actingAsRole('admin');
        $other = Client::factory()->create();
        $id = $this->postJson("/api/clients/{$other->id}/history-entries", $this->entryPayload())->json('data.entry.id');

        $this->putJson("/api/clients/{$this->client->id}/history-entries/{$id}", $this->entryPayload(['title' => 'x']))->assertNotFound();
        $this->deleteJson("/api/clients/{$this->client->id}/history-entries/{$id}")->assertNotFound();

        $this->assertSame(1, ClientHistoryEntry::where('client_id', $other->id)->count());
        $this->assertSame(['client_registered'], array_column($this->getJson("/api/clients/{$this->client->id}/history")->json('data.timeline'), 'type'));
    }

    public function test_entry_keeps_existing_when_linked_shoot_is_deleted(): void
    {
        $this->actingAsRole('admin');
        $shoot = $this->shoot('completed', now()->subDay()->toDateTimeString());
        $this->postJson("/api/clients/{$this->client->id}/history-entries", $this->entryPayload(['appointment_id' => $shoot->id]))->assertCreated();

        $shoot->delete();

        $entry = collect($this->getJson("/api/clients/{$this->client->id}/history")->json('data.timeline'))->firstWhere('source', 'manual');
        $this->assertNotNull($entry);
        $this->assertNull($entry['appointment']);
    }

    public function test_automatic_events_are_not_editable(): void
    {
        $this->actingAsRole('admin');

        $event = $this->getJson("/api/clients/{$this->client->id}/history")->json('data.timeline.0');

        $this->assertSame('auto', $event['source']);
        $this->assertArrayNotHasKey('entry', $event);
    }
}
