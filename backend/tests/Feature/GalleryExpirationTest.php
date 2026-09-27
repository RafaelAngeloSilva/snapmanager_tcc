<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Validade da galeria e dados usados no compartilhamento.
 *
 * O formulário passou a enviar o INSTANTE escolhido (ISO-8601 com fuso) em
 * vez do horário "solto". Antes, "18:00" escolhido em Brasília chegava sem
 * fuso e era gravado como 18:00 UTC — a galeria expirava três horas antes
 * do combinado, e uma data válida podia até ser recusada como passada.
 */
class GalleryExpirationTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->client = Client::factory()->create(['phone' => '(11) 98765-4321']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'client_id' => $this->client->id,
            'title' => 'Ensaio de casamento',
            ...$overrides,
        ];
    }

    public function test_expiration_is_stored_as_the_exact_instant_chosen(): void
    {
        // 18:00 em Brasília (offset -03:00) = 21:00 UTC.
        $response = $this->postJson('/api/galleries', $this->payload(['expires_at' => '2026-10-01T18:00:00.000-03:00']))
            ->assertCreated();

        $gallery = Gallery::findOrFail($response->json('data.id'));

        $this->assertSame('2026-10-01 21:00:00', $gallery->expires_at->utc()->format('Y-m-d H:i:s'));
        // E volta para a tela como o mesmo instante.
        $this->assertTrue(Carbon::parse($response->json('data.expires_at'))->equalTo(Carbon::parse('2026-10-01T18:00:00-03:00')));
    }

    public function test_forty_eight_hours_shortcut_keeps_exactly_forty_eight_hours(): void
    {
        // O atalho manda "agora + 48h" como instante.
        $expiresAt = now()->addHours(48);

        $response = $this->postJson('/api/galleries', $this->payload(['expires_at' => $expiresAt->toIso8601String()]))->assertCreated();

        $gallery = Gallery::findOrFail($response->json('data.id'));

        $this->assertSame(48, (int) round(now()->diffInMinutes($gallery->expires_at) / 60));
        $this->assertTrue($gallery->isAccessible(), 'uma galeria que expira em 48h nasce acessível');
    }

    public function test_expiration_in_the_near_future_is_accepted_even_close_to_midnight_utc(): void
    {
        // Caso que o envio sem fuso quebrava: fim de tarde em Brasília já é
        // "amanhã" em UTC para quem digita só a hora local.
        $this->travelTo(Carbon::parse('2026-10-01 23:30:00', 'UTC'));

        // 22:00 de 01/10 em Brasília = 01:00 de 02/10 em UTC: continua no
        // futuro, e o back-end precisa enxergar isso.
        $this->postJson('/api/galleries', $this->payload(['expires_at' => '2026-10-01T22:00:00-03:00']))
            ->assertCreated();
    }

    public function test_expiration_in_the_past_is_still_rejected(): void
    {
        $this->postJson('/api/galleries', $this->payload(['expires_at' => now()->subHour()->toIso8601String()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expires_at');
    }

    public function test_gallery_without_expiration_is_still_allowed(): void
    {
        $response = $this->postJson('/api/galleries', $this->payload())->assertCreated();

        $this->assertNull(Gallery::findOrFail($response->json('data.id'))->expires_at);
    }

    public function test_editing_other_fields_never_changes_the_existing_expiration(): void
    {
        $gallery = Gallery::factory()->create([
            'client_id' => $this->client->id,
            'expires_at' => Carbon::parse('2026-11-20 14:00:00', 'UTC'),
        ]);

        $this->putJson("/api/galleries/{$gallery->id}", [
            'client_id' => $this->client->id,
            'title' => 'Título novo',
        ])->assertOk();

        $this->assertSame('2026-11-20 14:00:00', $gallery->fresh()->expires_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_resending_the_same_expiration_is_accepted_even_if_already_past(): void
    {
        $gallery = Gallery::factory()->create([
            'client_id' => $this->client->id,
            'expires_at' => Carbon::parse('2020-01-01 10:00:00', 'UTC'),
        ]);

        // A tela reenvia o mesmo instante ao editar outro campo.
        $this->putJson("/api/galleries/{$gallery->id}", [
            'client_id' => $this->client->id,
            'title' => 'Galeria antiga',
            'expires_at' => $gallery->expires_at->toIso8601String(),
        ])->assertOk();

        $this->assertSame('2020-01-01 10:00:00', $gallery->fresh()->expires_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_client_phone_is_available_for_whatsapp_sharing(): void
    {
        $gallery = Gallery::factory()->create(['client_id' => $this->client->id]);

        $this->getJson("/api/galleries/{$gallery->id}")
            ->assertOk()
            ->assertJsonPath('data.client.phone', '(11) 98765-4321');
    }

    public function test_public_gallery_never_exposes_client_contact(): void
    {
        $gallery = Gallery::factory()->create(['client_id' => $this->client->id]);

        $response = $this->getJson("/api/public/galleries/{$gallery->access_token}")->assertOk();

        $this->assertStringNotContainsString('98765-4321', $response->getContent());
        $this->assertStringNotContainsString($this->client->email, $response->getContent());
    }
}
