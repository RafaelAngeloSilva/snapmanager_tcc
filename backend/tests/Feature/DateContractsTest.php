<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Document;
use App\Models\KanbanColumn;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrato dos campos de data de cada módulo.
 *
 * O calendário do SnapManager (DateTimePicker) substituiu os seletores
 * nativos, mas manda EXATAMENTE o mesmo formato que eles mandavam. Estes
 * testes fixam esse contrato — se alguém mudar o formato sem querer, eles
 * quebram:
 *
 *   Agenda       starts_at/ends_at  "YYYY-MM-DDTHH:mm"  horário do estúdio
 *   Pedidos      due_date           "YYYY-MM-DD"        data civil
 *   Documentos   expires_at         "YYYY-MM-DD"        data civil
 *   Produção     due_date           "YYYY-MM-DD"        data civil
 *
 * (A validade da galeria é um INSTANTE e tem testes próprios em
 * GalleryExpirationTest.)
 */
class DateContractsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $photographer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->client = Client::factory()->create();
        $this->photographer = User::factory()->create(['role' => 'photographer']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function appointmentPayload(array $overrides = []): array
    {
        return [
            'client_id' => $this->client->id,
            'user_id' => $this->photographer->id,
            'title' => 'Ensaio externo',
            'starts_at' => Carbon::now()->addDays(3)->format('Y-m-d\TH:i'),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2)->format('Y-m-d\TH:i'),
            ...$overrides,
        ];
    }

    // ------------------------------------------------ Agenda

    public function test_appointment_keeps_the_chosen_wall_clock_time(): void
    {
        $starts = Carbon::now()->addDays(5)->setTime(14, 30);
        $ends = $starts->copy()->addHours(2);

        $response = $this->postJson('/api/appointments', $this->appointmentPayload([
            'starts_at' => $starts->format('Y-m-d\TH:i'),
            'ends_at' => $ends->format('Y-m-d\TH:i'),
        ]))->assertCreated();

        // O horário escolhido é o horário gravado: sem deslocamento de fuso
        // em nenhum sentido (a Agenda é "horário do estúdio", e o Dashboard
        // agrupa por date(starts_at) contando com isso).
        $this->assertSame(
            $starts->format('Y-m-d H:i:00'),
            DB::table('appointments')->where('id', $response->json('data.id'))->value('starts_at'),
        );
        $this->assertSame($starts->format('Y-m-d\TH:i'), Carbon::parse($response->json('data.starts_at'))->format('Y-m-d\TH:i'));
    }

    public function test_appointment_end_must_be_after_start(): void
    {
        $starts = Carbon::now()->addDays(4)->setTime(10, 0);

        $this->postJson('/api/appointments', $this->appointmentPayload([
            'starts_at' => $starts->format('Y-m-d\TH:i'),
            'ends_at' => $starts->copy()->subHour()->format('Y-m-d\TH:i'),
        ]))->assertStatus(422)->assertJsonValidationErrors('ends_at');

        // Mesmo instante também não vale como intervalo.
        $this->postJson('/api/appointments', $this->appointmentPayload([
            'starts_at' => $starts->format('Y-m-d\TH:i'),
            'ends_at' => $starts->format('Y-m-d\TH:i'),
        ]))->assertStatus(422)->assertJsonValidationErrors('ends_at');

        $this->assertSame(0, Appointment::count());
    }

    public function test_appointment_conflict_check_still_applies(): void
    {
        $starts = Carbon::now()->addDays(6)->setTime(9, 0);

        $this->postJson('/api/appointments', $this->appointmentPayload([
            'starts_at' => $starts->format('Y-m-d\TH:i'),
            'ends_at' => $starts->copy()->addHours(3)->format('Y-m-d\TH:i'),
        ]))->assertCreated();

        // Mesmo profissional, horário sobreposto.
        $this->postJson('/api/appointments', $this->appointmentPayload([
            'starts_at' => $starts->copy()->addHour()->format('Y-m-d\TH:i'),
            'ends_at' => $starts->copy()->addHours(4)->format('Y-m-d\TH:i'),
        ]))->assertStatus(422);

        $this->assertSame(1, Appointment::count());
    }

    public function test_editing_an_appointment_without_touching_the_times_keeps_them(): void
    {
        $appointment = Appointment::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->photographer->id,
            'starts_at' => Carbon::parse('2026-11-10 14:00:00'),
            'ends_at' => Carbon::parse('2026-11-10 16:00:00'),
        ]);

        // A tela reenvia os mesmos valores ao salvar outro campo.
        $this->putJson("/api/appointments/{$appointment->id}", [
            'client_id' => $this->client->id,
            'title' => 'Ensaio externo (novo título)',
            'starts_at' => '2026-11-10T14:00',
            'ends_at' => '2026-11-10T16:00',
        ])->assertOk();

        $fresh = $appointment->fresh();
        $this->assertSame('2026-11-10 14:00:00', $fresh->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 16:00:00', $fresh->ends_at->format('Y-m-d H:i:s'));
    }

    // ------------------------------------------------ Pedidos (data civil)

    public function test_order_due_date_keeps_the_exact_day(): void
    {
        $day = Carbon::now()->addDays(10)->format('Y-m-d');

        $response = $this->postJson('/api/orders', [
            'client_id' => $this->client->id,
            'title' => 'Álbum 30x30',
            'due_date' => $day,
            'total' => 1500,
        ])->assertCreated();

        // Nada de "um dia a menos" por conversão de fuso.
        $this->assertSame($day, Order::findOrFail($response->json('data.id'))->due_date->format('Y-m-d'));
        $this->assertSame($day, substr($response->json('data.due_date'), 0, 10));
    }

    public function test_order_due_date_in_the_past_is_still_rejected(): void
    {
        $this->postJson('/api/orders', [
            'client_id' => $this->client->id,
            'title' => 'Álbum',
            'due_date' => Carbon::now()->subDay()->format('Y-m-d'),
        ])->assertStatus(422)->assertJsonValidationErrors('due_date');
    }

    // ------------------------------------------------ Documentos (data civil)

    public function test_document_signature_deadline_keeps_the_exact_day(): void
    {
        $day = Carbon::now()->addDays(15)->format('Y-m-d');

        $response = $this->post('/api/documents', [
            'title' => 'Contrato',
            'type' => 'photoshoot',
            'client_id' => $this->client->id,
            'expires_at' => $day,
            'file' => UploadedFile::fake()->createWithContent('contrato.pdf', "%PDF-1.4\n%%EOF"),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame($day, Document::findOrFail($response->json('data.id'))->expires_at->format('Y-m-d'));
        $this->assertSame($day, $response->json('data.expires_at'));
        Http::assertNothingSent();
    }

    public function test_document_deadline_limits_are_enforced(): void
    {
        $file = fn () => UploadedFile::fake()->createWithContent('contrato.pdf', "%PDF-1.4\n%%EOF");
        $payload = fn (string $day) => [
            'title' => 'Contrato',
            'type' => 'photoshoot',
            'client_id' => $this->client->id,
            'expires_at' => $day,
            'file' => $file(),
        ];

        // Hoje não vale (a regra é "depois de hoje") e acima de 90 dias também não.
        $this->post('/api/documents', $payload(Carbon::now()->format('Y-m-d')), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('expires_at');
        $this->post('/api/documents', $payload(Carbon::now()->addDays(91)->format('Y-m-d')), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('expires_at');

        // Amanhã e o 90º dia valem — os mesmos limites que o calendário mostra.
        $this->post('/api/documents', $payload(Carbon::now()->addDay()->format('Y-m-d')), ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/documents', $payload(Carbon::now()->addDays(90)->format('Y-m-d')), ['Accept' => 'application/json'])->assertCreated();
    }

    // ------------------------------------------------ Produção (data civil)

    public function test_kanban_task_due_date_keeps_the_exact_day(): void
    {
        $column = KanbanColumn::create(['name' => 'A fazer', 'position' => 0]);
        $day = Carbon::now()->addDays(7)->format('Y-m-d');

        $response = $this->postJson('/api/kanban/tasks', [
            'kanban_column_id' => $column->id,
            'title' => 'Tratar fotos',
            'due_date' => $day,
        ])->assertCreated();

        $this->assertSame($day, substr($response->json('data.due_date'), 0, 10));
    }
}
