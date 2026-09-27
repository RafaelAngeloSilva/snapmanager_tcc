<?php

namespace Tests\Feature;

use App\Enums\DocumentSignerStatus;
use App\Enums\DocumentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Document;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seção "Documentos vinculados" de Pedidos e Agenda: reaproveita
 * GET /api/documents com os filtros order_id / appointment_id. Nenhuma
 * chamada à Clicksign acontece aqui (preventStrayRequests).
 */
class LinkedDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Order $order;

    private Order $otherOrder;

    private Appointment $appointment;

    private Appointment $otherAppointment;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');

        $this->client = Client::factory()->create();
        $this->appointment = Appointment::factory()->create(['client_id' => $this->client->id]);
        $this->otherAppointment = Appointment::factory()->create(['client_id' => $this->client->id]);
        $this->order = Order::factory()->create(['client_id' => $this->client->id, 'appointment_id' => $this->appointment->id]);
        $this->otherOrder = Order::factory()->create(['client_id' => $this->client->id]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeDocument(array $attributes = [], int $signers = 1, int $signed = 0): Document
    {
        $document = new Document([
            'client_id' => $this->client->id,
            'title' => 'Contrato',
            'type' => 'photoshoot',
            'original_file_path' => 'documents/teste.pdf',
            'original_file_name' => 'contrato.pdf',
            'original_file_size' => 100,
            ...$attributes,
        ]);
        $document->status = $attributes['status'] ?? DocumentStatus::Draft;
        $document->save();

        for ($i = 0; $i < $signers; $i++) {
            $signer = $document->signers()->create(['name' => "Pessoa Teste{$i}", 'channel' => 'email', 'email' => "p{$i}@example.com"]);

            if ($i < $signed) {
                $signer->forceFill(['status' => DocumentSignerStatus::Signed, 'signed_at' => now()])->save();
            }
        }

        return $document;
    }

    /** @return array<int, int> */
    private function idsFor(string $query): array
    {
        return collect($this->getJson("/api/documents?{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    // ------------------------------------------------ pedidos

    public function test_order_shows_only_its_own_documents_including_several(): void
    {
        $contract = $this->makeDocument(['order_id' => $this->order->id, 'title' => 'Prestação de serviços']);
        $authorization = $this->makeDocument(['order_id' => $this->order->id, 'title' => 'Uso de imagem', 'type' => 'image_use_authorization']);
        $elsewhere = $this->makeDocument(['order_id' => $this->otherOrder->id]);
        $unlinked = $this->makeDocument();

        $this->actingAsRole('attendant');

        $ids = $this->idsFor("order_id={$this->order->id}");
        $this->assertSame(collect([$contract->id, $authorization->id])->sort()->values()->all(), $ids);
        $this->assertNotContains($elsewhere->id, $ids);
        $this->assertNotContains($unlinked->id, $ids);
    }

    public function test_order_without_documents_returns_empty_list(): void
    {
        $this->makeDocument(['order_id' => $this->otherOrder->id]);

        $this->actingAsRole('admin');

        $this->getJson("/api/documents?order_id={$this->order->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------ agenda

    public function test_appointment_shows_only_its_own_documents(): void
    {
        $mine = $this->makeDocument(['appointment_id' => $this->appointment->id]);
        $alsoMine = $this->makeDocument(['appointment_id' => $this->appointment->id, 'type' => 'image_use_authorization']);
        $other = $this->makeDocument(['appointment_id' => $this->otherAppointment->id]);

        $this->actingAsRole('attendant');

        $ids = $this->idsFor("appointment_id={$this->appointment->id}");
        $this->assertSame(collect([$mine->id, $alsoMine->id])->sort()->values()->all(), $ids);
        $this->assertNotContains($other->id, $ids);

        $this->getJson("/api/documents?appointment_id={$this->otherAppointment->id}")->assertJsonCount(1, 'data');
    }

    public function test_appointment_without_documents_returns_empty_list(): void
    {
        $this->actingAsRole('admin');

        $this->getJson("/api/documents?appointment_id={$this->appointment->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------ autenticação / autorização

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson("/api/documents?order_id={$this->order->id}")->assertUnauthorized();
    }

    public function test_filters_never_bypass_document_visibility(): void
    {
        // Editor só vê documentos vinculados a pedido. Filtrar pelo
        // agendamento não "abre" um documento que só tem agendamento.
        $onlyAppointment = $this->makeDocument(['appointment_id' => $this->appointment->id]);
        $withOrder = $this->makeDocument(['appointment_id' => $this->appointment->id, 'order_id' => $this->order->id]);

        $this->actingAsRole('editor');

        $this->assertSame([$withOrder->id], $this->idsFor("appointment_id={$this->appointment->id}"));
        $this->getJson("/api/documents/{$onlyAppointment->id}")->assertForbidden();
    }

    public function test_read_only_profiles_cannot_use_management_actions_of_the_section(): void
    {
        $document = $this->makeDocument([
            'order_id' => $this->order->id,
            'status' => DocumentStatus::Signed,
            'clicksign_envelope_id' => 'env',
            'clicksign_document_id' => 'doc',
        ]);

        foreach (['photographer', 'editor'] as $role) {
            $this->actingAsRole($role);

            // Ver o pedido não dá direito a sincronizar, baixar o assinado
            // nem criar documento a partir dele.
            $this->postJson("/api/documents/{$document->id}/sync")->assertForbidden();
            $this->get("/api/documents/{$document->id}/download-signed")->assertForbidden();
            $this->post('/api/documents', [], ['Accept' => 'application/json'])->assertForbidden();
            $this->getJson("/api/documents/link-options?client_id={$this->client->id}")->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_changing_the_id_does_not_expose_other_documents(): void
    {
        $document = $this->makeDocument(['order_id' => $this->otherOrder->id]);

        $this->actingAsRole('editor');

        // Pedir por um pedido não "puxa" documentos de outro pedido...
        $this->assertSame([], $this->idsFor("order_id={$this->order->id}"));
        // ...e um id inválido no filtro é recusado, não ignorado.
        $this->getJson('/api/documents?order_id=abc')->assertStatus(422);
        // A Policy continua valendo no detalhe.
        $this->getJson("/api/documents/{$document->id}")->assertOk();
    }

    public function test_download_from_linked_section_respects_authorization(): void
    {
        Storage::disk('local')->put('documents/teste.pdf', "%PDF-1.4\n%%EOF");
        $document = $this->makeDocument(['order_id' => $this->order->id]);

        foreach (['photographer', 'editor'] as $role) {
            $this->actingAsRole($role);

            $this->getJson("/api/documents?order_id={$this->order->id}")
                ->assertOk()
                ->assertJsonPath('data.0.abilities.download_original', false)
                ->assertJsonPath('data.0.abilities.sync', false);
            $this->get("/api/documents/{$document->id}/file")->assertForbidden();
        }

        $this->actingAsRole('attendant');
        $this->getJson("/api/documents?order_id={$this->order->id}")->assertJsonPath('data.0.abilities.download_original', true);
        $this->get("/api/documents/{$document->id}/file")->assertOk();
    }

    public function test_linked_list_does_not_leak_contacts_history_or_paths(): void
    {
        $this->makeDocument(['order_id' => $this->order->id]);

        $this->actingAsRole('photographer');

        $response = $this->getJson("/api/documents?order_id={$this->order->id}")->assertOk();
        $response->assertJsonMissingPath('data.0.signers.0.email');
        $response->assertJsonMissingPath('data.0.events.0');
        $this->assertStringNotContainsString('documents/teste.pdf', $response->getContent());
    }

    // ------------------------------------------------ status

    public function test_partial_signature_is_not_shown_as_finished(): void
    {
        $this->makeDocument([
            'order_id' => $this->order->id,
            'status' => DocumentStatus::PartiallySigned,
            'clicksign_envelope_id' => 'env',
            'clicksign_document_id' => 'doc',
        ], signers: 2, signed: 1);

        $this->actingAsRole('attendant');

        $this->getJson("/api/documents?order_id={$this->order->id}")
            ->assertJsonPath('data.0.status', 'partially_signed')
            ->assertJsonPath('data.0.signed_count', 1)
            ->assertJsonPath('data.0.signers_count', 2)
            ->assertJsonPath('data.0.has_signed_file', false)
            ->assertJsonPath('data.0.abilities.download_signed', false);
    }

    // ------------------------------------------------ Novo documento a partir do contexto

    public function test_document_created_from_order_context_is_linked_to_it(): void
    {
        $this->actingAsRole('attendant');

        // Exatamente o que o formulário envia com a pré-seleção do pedido
        // (cliente + pedido + agendamento do pedido).
        $this->post('/api/documents', [
            'title' => 'Autorização de uso de imagem',
            'type' => 'image_use_authorization',
            'client_id' => $this->client->id,
            'order_id' => $this->order->id,
            'appointment_id' => $this->appointment->id,
            'file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $this->assertCount(1, $this->getJson("/api/documents?order_id={$this->order->id}")->json('data'));
        $this->assertCount(1, $this->getJson("/api/documents?appointment_id={$this->appointment->id}")->json('data'));
        // Criar a partir do contexto não fala com a Clicksign.
        Http::assertNothingSent();
    }

    public function test_preselected_context_from_another_client_is_rejected(): void
    {
        $stranger = Client::factory()->create();

        $this->actingAsRole('attendant');

        $this->post('/api/documents', [
            'title' => 'Contrato',
            'type' => 'photoshoot',
            'client_id' => $stranger->id,
            'order_id' => $this->order->id,
            'file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('order_id');
    }
}
