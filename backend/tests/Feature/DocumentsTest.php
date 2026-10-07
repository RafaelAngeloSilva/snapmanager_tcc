<?php

namespace Tests\Feature;

use App\Enums\DocumentSignerStatus;
use App\Enums\DocumentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Módulo Documentos. Nada aqui fala com a Clicksign de verdade:
 * preventStrayRequests() + Http::fake() com token FICTÍCIO. Os arquivos vão
 * para um disco falso (Storage::fake).
 */
class DocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_TOKEN = 'token-ficticio-de-teste-0000-1111';

    private const WEBHOOK_SECRET = 'segredo-ficticio-do-webhook';

    private const ENV = '11111111-1111-4111-8111-111111111111';

    private const DOC = '22222222-2222-4222-8222-222222222222';

    private const SIGNER_A = '33333333-3333-4333-8333-333333333333';

    private const SIGNER_B = '44444444-4444-4444-8444-444444444444';

    private const SIGNED_URL = 'https://clicksign-sandbox-content.s3.amazonaws.com/arquivo-assinado.pdf?X-Amz-Signature=abc';

    private Client $client;

    /** @var array<string, mixed> Respostas trocadas no meio do teste (ver fakeClicksign). */
    private array $clicksignOverrides = [];

    private bool $clicksignFaked = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');

        config([
            'services.clicksign.base_url' => 'https://sandbox.clicksign.com/api/v3',
            'services.clicksign.access_token' => self::FAKE_TOKEN,
            'services.clicksign.webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        $this->client = Client::factory()->create(['name' => 'Maria Cliente']);
    }

    // ---------------------------------------------------------------- helpers

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function pdf(string $name = 'contrato.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n");
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Contrato de ensaio',
            'type' => 'photoshoot',
            'client_id' => $this->client->id,
            'file' => $this->pdf(),
            'signers' => [
                ['name' => 'Maria Cliente', 'channel' => 'email', 'email' => 'maria@example.com'],
            ],
            ...$overrides,
        ];
    }

    /**
     * Cria um documento pelo próprio endpoint (como admin) e devolve o model.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createDocument(array $overrides = []): Document
    {
        $previous = auth()->user();
        $this->actingAsRole('admin');

        $id = $this->post('/api/documents', $this->payload($overrides), ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        if ($previous) {
            Sanctum::actingAs($previous);
        }

        return Document::findOrFail($id);
    }

    /** Documento já enviado (awaiting_signature), com dois signatários. */
    private function sentDocument(): Document
    {
        $document = $this->createDocument(['signers' => [
            ['name' => 'Maria Cliente', 'channel' => 'email', 'email' => 'maria@example.com'],
            ['name' => 'João Parceiro', 'channel' => 'whatsapp', 'phone_number' => '11987654321'],
        ]]);

        $document->forceFill([
            'status' => DocumentStatus::AwaitingSignature,
            'clicksign_envelope_id' => self::ENV,
            'clicksign_document_id' => self::DOC,
            'sent_at' => now(),
        ])->save();

        $document->signers[0]->forceFill(['clicksign_signer_id' => self::SIGNER_A])->save();
        $document->signers[1]->forceFill(['clicksign_signer_id' => self::SIGNER_B])->save();

        return $document->refresh();
    }

    /**
     * Simula a Clicksign roteando por "MÉTODO caminho".
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeClicksign(array $overrides = []): void
    {
        // Http::fake() chamado de novo NÃO substitui o callback anterior (o
        // primeiro continua respondendo). Por isso o fake é registrado uma
        // vez só e as trocas de resposta passam por uma propriedade.
        $this->clicksignOverrides = $overrides;

        if ($this->clicksignFaked) {
            return;
        }

        $this->clicksignFaked = true;
        $env = '/envelopes/'.self::ENV;
        $signerIds = [self::SIGNER_A, self::SIGNER_B];

        Http::fake(function (Request $request) use ($env, &$signerIds) {
            $overrides = $this->clicksignOverrides;

            if (str_starts_with($request->url(), 'https://clicksign-sandbox-content.s3.amazonaws.com/')) {
                return $overrides['S3'] ?? Http::response("%PDF-1.7 assinado\n%%EOF", 200, ['Content-Type' => 'application/pdf']);
            }

            $key = $request->method().' '.substr(parse_url($request->url(), PHP_URL_PATH), strlen('/api/v3'));

            if (array_key_exists($key, $overrides)) {
                $override = $overrides[$key];

                return $override instanceof \Closure ? $override($request) : $override;
            }

            return match ($key) {
                'POST /envelopes' => Http::response(['data' => ['id' => self::ENV, 'type' => 'envelopes', 'attributes' => ['status' => 'draft']]], 201),
                "POST {$env}/documents" => Http::response(['data' => ['id' => self::DOC, 'type' => 'documents']], 201),
                "POST {$env}/signers" => Http::response(['data' => ['id' => array_shift($signerIds) ?? 'extra', 'type' => 'signers']], 201),
                "POST {$env}/requirements" => Http::response(['data' => ['id' => 'req-'.uniqid(), 'type' => 'requirements']], 201),
                "PATCH {$env}" => Http::response(['data' => ['id' => self::ENV, 'type' => 'envelopes', 'attributes' => ['status' => 'running']]]),
                "POST {$env}/notifications" => Http::response(['data' => ['id' => 'n1', 'type' => 'notifications']]),
                "GET {$env}" => Http::response(['data' => ['id' => self::ENV, 'attributes' => ['status' => 'running']]]),
                "GET {$env}/documents/".self::DOC.'/events' => Http::response(['data' => []]),
                "GET {$env}/documents/".self::DOC => Http::response(['data' => ['id' => self::DOC, 'links' => ['files' => ['original' => 'https://clicksign-sandbox-content.s3.amazonaws.com/original.pdf', 'signed' => self::SIGNED_URL]]]]),
                "PATCH {$env}/documents/".self::DOC => Http::response(['data' => ['id' => self::DOC, 'attributes' => ['status' => 'canceled']]]),
                "DELETE {$env}" => Http::response(null, 204),
                default => Http::response(['errors' => [['detail' => "rota não simulada: {$key}"]]], 404),
            };
        });
    }

    /** @return array<int, string> */
    private function sentCalls(): array
    {
        return collect(Http::recorded())->map(function ($pair) {
            $url = $pair[0]->url();

            return str_contains($url, 'amazonaws.com') ? 'GET [S3]' : $pair[0]->method().' '.substr(parse_url($url, PHP_URL_PATH), strlen('/api/v3'));
        })->values()->all();
    }

    /** @param  array<int, array<string, mixed>>  $events */
    private function remoteEvents(array $events): \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['data' => array_map(fn ($e) => ['type' => 'events', 'attributes' => $e], $events)]);
    }

    private function signWebhook(string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
    }

    /** @param  array<string, mixed>  $payload */
    private function postWebhook(array $payload, ?string $hmac = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/clicksign', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CONTENT_HMAC' => $hmac ?? $this->signWebhook($body),
            'HTTP_EVENT' => $payload['event']['name'] ?? '',
        ], $body);
    }

    // ------------------------------------------------ 1-2. autenticação/autorização

    public function test_unauthenticated_requests_get_401(): void
    {
        $this->getJson('/api/documents')->assertUnauthorized();
        $this->postJson('/api/documents', [])->assertUnauthorized();
    }

    public function test_read_only_profiles_cannot_create_or_operate(): void
    {
        $document = $this->sentDocument();

        foreach (['photographer', 'editor'] as $role) {
            $this->actingAsRole($role);

            $this->post('/api/documents', $this->payload(), ['Accept' => 'application/json'])->assertForbidden();
            $this->postJson("/api/documents/{$document->id}/send")->assertForbidden();
            $this->postJson("/api/documents/{$document->id}/notify")->assertForbidden();
            $this->postJson("/api/documents/{$document->id}/sync")->assertForbidden();
            $this->postJson("/api/documents/{$document->id}/cancel")->assertForbidden();
            $this->deleteJson("/api/documents/{$document->id}")->assertForbidden();
            $this->get("/api/documents/{$document->id}/file")->assertForbidden();
        }
    }

    public function test_attendant_cannot_cancel_or_delete(): void
    {
        $sent = $this->sentDocument();
        $draft = $this->createDocument();

        $this->actingAsRole('attendant');

        $this->postJson("/api/documents/{$sent->id}/cancel")->assertForbidden();
        $this->deleteJson("/api/documents/{$draft->id}")->assertForbidden();
    }

    public function test_editor_only_sees_documents_linked_to_an_order_even_by_id(): void
    {
        $order = Order::factory()->create(['client_id' => $this->client->id]);
        $withOrder = $this->createDocument(['order_id' => $order->id, 'title' => 'Com pedido']);
        $withoutOrder = $this->createDocument(['title' => 'Sem pedido']);

        $this->actingAsRole('editor');

        $this->getJson('/api/documents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $withOrder->id);

        // IDOR: trocar o id na URL não expõe o documento escondido da listagem.
        $this->getJson("/api/documents/{$withoutOrder->id}")->assertForbidden();
        $this->getJson("/api/documents/{$withOrder->id}")->assertOk();
    }

    public function test_read_only_profiles_do_not_see_signer_contacts_or_technical_ids(): void
    {
        $document = $this->sentDocument();

        $this->actingAsRole('photographer');
        $response = $this->getJson("/api/documents/{$document->id}")->assertOk();

        $response->assertJsonMissingPath('data.signers.0.email');
        $response->assertJsonMissingPath('data.signers.0.phone_number');
        $response->assertJsonMissingPath('data.technical');
        $response->assertJsonPath('data.abilities.send', false);

        $this->actingAsRole('admin');
        $this->getJson("/api/documents/{$document->id}")
            ->assertJsonPath('data.signers.0.email', 'maria@example.com')
            ->assertJsonPath('data.technical.clicksign_envelope_id', self::ENV);
    }

    // ------------------------------------------------ 3-7. criação e vínculos

    public function test_create_document_as_draft_in_private_storage(): void
    {
        $this->actingAsRole('attendant');

        $response = $this->post('/api/documents', $this->payload(['notes' => 'Obs']), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.client.name', 'Maria Cliente')
            ->assertJsonPath('data.signers_count', 1)
            ->assertJsonPath('data.events.0.event', 'created');

        $document = Document::findOrFail($response->json('data.id'));

        Storage::disk('local')->assertExists($document->original_file_path);
        // Nome no disco é gerado (UUID), não o nome enviado; e o caminho não vaza.
        $this->assertMatchesRegularExpression('#^documents/original/[0-9a-f-]{36}\.pdf$#', $document->original_file_path);
        $this->assertStringNotContainsString($document->original_file_path, $response->getContent());
        // Nenhuma chamada à Clicksign ao salvar.
        Http::assertNothingSent();
    }

    public function test_status_sent_by_the_client_is_ignored(): void
    {
        $this->actingAsRole('admin');

        $this->post('/api/documents', $this->payload(['status' => 'signed']), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_rejects_files_that_are_not_real_pdfs(): void
    {
        $this->actingAsRole('admin');

        // Texto com extensão .pdf: o conteúdo não é PDF.
        $fake = UploadedFile::fake()->createWithContent('contrato.pdf', 'isto não é um pdf');
        $this->post('/api/documents', $this->payload(['file' => $fake]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        // PDF de verdade, mas com extensão errada.
        $wrongExt = UploadedFile::fake()->createWithContent('contrato.exe', "%PDF-1.4\n%%EOF");
        $this->post('/api/documents', $this->payload(['file' => $wrongExt]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        // Acima de 10 MB.
        $huge = UploadedFile::fake()->create('grande.pdf', 11 * 1024, 'application/pdf');
        $this->post('/api/documents', $this->payload(['file' => $huge]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, Document::count());
    }

    public function test_links_order_and_appointment_of_the_same_client(): void
    {
        $order = Order::factory()->create(['client_id' => $this->client->id]);
        $appointment = Appointment::factory()->create(['client_id' => $this->client->id]);

        $this->actingAsRole('admin');

        $this->post('/api/documents', $this->payload(['order_id' => $order->id, 'appointment_id' => $appointment->id]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.order.id', $order->id)
            ->assertJsonPath('data.appointment.id', $appointment->id);
    }

    public function test_cannot_link_records_of_another_client(): void
    {
        $other = Client::factory()->create();
        $order = Order::factory()->create(['client_id' => $other->id]);
        $appointment = Appointment::factory()->create(['client_id' => $other->id]);

        $this->actingAsRole('admin');

        $this->post('/api/documents', $this->payload(['order_id' => $order->id, 'appointment_id' => $appointment->id]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['order_id', 'appointment_id']);
    }

    public function test_link_options_only_list_records_of_the_chosen_client(): void
    {
        $mine = Order::factory()->create(['client_id' => $this->client->id]);
        Order::factory()->create(['client_id' => Client::factory()->create()->id]);
        $appointment = Appointment::factory()->create(['client_id' => $this->client->id]);

        $this->actingAsRole('attendant');

        $this->getJson("/api/documents/link-options?client_id={$this->client->id}")
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $mine->id)
            ->assertJsonPath('appointments.0.id', $appointment->id);

        $this->actingAsRole('photographer');
        $this->getJson("/api/documents/link-options?client_id={$this->client->id}")->assertForbidden();
    }

    // ------------------------------------------------ 8-10. signatários

    public function test_email_and_whatsapp_signers_with_phone_normalization(): void
    {
        $this->actingAsRole('admin');

        $response = $this->post('/api/documents', $this->payload(['signers' => [
            ['name' => 'Maria Cliente', 'channel' => 'email', 'email' => 'Maria@Example.com'],
            ['name' => 'João Parceiro', 'channel' => 'whatsapp', 'phone_number' => '+55 (11) 98765-4321'],
        ]]), ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonPath('data.signers.0.channel', 'email')
            ->assertJsonPath('data.signers.0.email', 'maria@example.com')
            ->assertJsonPath('data.signers.1.channel', 'whatsapp')
            // Formato Clicksign: DDD + número, só dígitos, sem DDI.
            ->assertJsonPath('data.signers.1.phone_number', '11987654321');
    }

    public function test_signer_contact_is_required_by_channel(): void
    {
        $this->actingAsRole('admin');

        $this->post('/api/documents', $this->payload(['signers' => [
            ['name' => 'Maria Cliente', 'channel' => 'email'],
            ['name' => 'João Parceiro', 'channel' => 'whatsapp'],
            ['name' => 'Semsobrenome', 'channel' => 'email', 'email' => 'a@example.com'],
            ['name' => 'Ana Telefone', 'channel' => 'whatsapp', 'phone_number' => '123'],
        ]]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['signers.0.email', 'signers.1.phone_number', 'signers.2.name', 'signers.3.phone_number']);
    }

    public function test_signers_can_only_change_while_draft(): void
    {
        $draft = $this->createDocument();
        $sent = $this->sentDocument();

        $this->actingAsRole('attendant');

        $this->postJson("/api/documents/{$draft->id}/signers", ['name' => 'Ana Nova', 'channel' => 'email', 'email' => 'ana@example.com'])
            ->assertCreated()->assertJsonPath('data.signers_count', 2);

        $this->postJson("/api/documents/{$sent->id}/signers", ['name' => 'Ana Nova', 'channel' => 'email', 'email' => 'ana@example.com'])
            ->assertStatus(409);

        // Signatário de OUTRO documento não é resolvido na rota (scoped binding).
        $foreignSigner = $sent->signers->first();
        $this->deleteJson("/api/documents/{$draft->id}/signers/{$foreignSigner->id}")->assertNotFound();
    }

    // ------------------------------------------------ 11-12. envio

    public function test_send_creates_and_activates_envelope_without_notifying(): void
    {
        $this->fakeClicksign();
        $document = $this->createDocument(['signers' => [
            ['name' => 'Maria Cliente', 'channel' => 'email', 'email' => 'maria@example.com'],
            ['name' => 'João Parceiro', 'channel' => 'whatsapp', 'phone_number' => '11987654321'],
        ]]);

        $this->actingAsRole('attendant');

        $this->postJson("/api/documents/{$document->id}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_signature')
            ->assertJsonPath('data.abilities.notify', true);

        $env = '/envelopes/'.self::ENV;
        $this->assertSame([
            'POST /envelopes',
            "POST {$env}/documents",
            "POST {$env}/signers",
            "POST {$env}/requirements",
            "POST {$env}/requirements",
            "POST {$env}/signers",
            "POST {$env}/requirements",
            "POST {$env}/requirements",
            "PATCH {$env}",
            // sem POST .../notifications: notificar é uma ação separada
        ], $this->sentCalls());

        $document->refresh()->load('signers');
        $this->assertSame(self::ENV, $document->clicksign_envelope_id);
        $this->assertSame(self::DOC, $document->clicksign_document_id);
        $this->assertSame([self::SIGNER_A, self::SIGNER_B], $document->signers->pluck('clicksign_signer_id')->all());
        $this->assertNotNull($document->sent_at);

        // communicate_events por canal, conforme a documentação da API 3.0.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/signers') && ($r['data']['attributes']['email'] ?? null) === 'maria@example.com'
            && $r['data']['attributes']['communicate_events'] === ['signature_request' => 'email', 'signature_reminder' => 'email', 'document_signed' => 'email']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/signers') && ($r['data']['attributes']['phone_number'] ?? null) === '11987654321'
            && ! isset($r['data']['attributes']['email'])
            && $r['data']['attributes']['communicate_events'] === ['signature_request' => 'whatsapp', 'signature_reminder' => 'none', 'document_signed' => 'whatsapp']);

        // Autenticação pelo mesmo canal: e-mail para um, WhatsApp para o outro.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/requirements') && ($r['data']['attributes']['auth'] ?? null) === 'email'
            && $r['data']['relationships']['signer']['data']['id'] === self::SIGNER_A);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/requirements') && ($r['data']['attributes']['auth'] ?? null) === 'whatsapp'
            && $r['data']['relationships']['signer']['data']['id'] === self::SIGNER_B);

        // O PDF enviado é o do disco, em data URI.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/documents')
            && str_starts_with($r['data']['attributes']['content_base64'], 'data:application/pdf;base64,'));
    }

    public function test_send_requires_draft_and_at_least_one_signer(): void
    {
        $this->fakeClicksign();
        $noSigners = $this->createDocument(['signers' => []]);
        $sent = $this->sentDocument();

        $this->actingAsRole('admin');

        $this->postJson("/api/documents/{$noSigners->id}/send")->assertStatus(422);
        // Segundo envio (ex.: duplo clique) não cria outro envelope.
        $this->postJson("/api/documents/{$sent->id}/send")->assertStatus(409);

        Http::assertNothingSent();
    }

    // ------------------------------------------------ 13. falha parcial

    public function test_partial_failure_keeps_record_and_can_be_discarded(): void
    {
        $env = '/envelopes/'.self::ENV;
        $this->fakeClicksign(["POST {$env}/signers" => Http::response(['errors' => [['detail' => 'name - deve conter ao menos duas palavras']]], 422)]);
        $document = $this->createDocument();

        $this->actingAsRole('admin');

        $this->postJson("/api/documents/{$document->id}/send")
            ->assertStatus(502)
            ->assertJsonPath('reason', 'request_failed')
            ->assertJsonPath('data.status', 'integration_error')
            ->assertJsonPath('data.integration_error.step', 'cadastrar signatário Maria Cliente')
            ->assertJsonPath('data.abilities.discard_failed_send', true);

        $document->refresh();
        $this->assertSame(DocumentStatus::IntegrationError, $document->status);
        // Rastro para limpeza: o envelope criado continua registrado.
        $this->assertSame(self::ENV, $document->clicksign_envelope_id);
        $this->assertStringContainsString('deve conter ao menos duas palavras', $document->last_error);
        $this->assertDatabaseHas('document_events', ['document_id' => $document->id, 'event' => 'integration_error']);
        $this->assertNotContains("PATCH {$env}", $this->sentCalls());

        // Limpeza segura: confere que o remoto é rascunho e exclui.
        $this->fakeClicksign(["GET {$env}" => Http::response(['data' => ['id' => self::ENV, 'attributes' => ['status' => 'draft']]])]);

        $this->postJson("/api/documents/{$document->id}/discard-failed-send")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->assertContains("DELETE {$env}", $this->sentCalls());
        $document->refresh();
        $this->assertNull($document->clicksign_envelope_id);
        $this->assertNotNull(Document::find($document->id), 'o registro local nunca é apagado');
    }

    public function test_discard_refuses_when_remote_envelope_already_running(): void
    {
        $document = $this->sentDocument();
        $document->forceFill(['status' => DocumentStatus::IntegrationError])->save();
        $this->fakeClicksign(); // GET envelope → running

        $this->actingAsRole('admin');

        $this->postJson("/api/documents/{$document->id}/discard-failed-send")->assertStatus(409);
        $this->assertNotContains('DELETE /envelopes/'.self::ENV, $this->sentCalls());
    }

    // ------------------------------------------------ 14. notificação

    public function test_notify_is_explicit_and_protected_against_double_click(): void
    {
        $this->fakeClicksign();
        $document = $this->sentDocument();
        $draft = $this->createDocument();

        $this->actingAsRole('attendant');

        $this->postJson("/api/documents/{$document->id}/notify")->assertOk();
        $this->assertContains('POST /envelopes/'.self::ENV.'/notifications', $this->sentCalls());
        $this->assertNotNull($document->refresh()->last_notified_at);

        // Clique repetido logo em seguida: bloqueado, sem nova chamada.
        $this->postJson("/api/documents/{$document->id}/notify")->assertStatus(429);
        $this->assertCount(1, array_filter($this->sentCalls(), fn ($c) => str_ends_with($c, '/notifications')));

        // Depois do intervalo, reenvio é permitido e fica registrado como reenvio.
        $this->travel(2)->minutes();
        $this->postJson("/api/documents/{$document->id}/notify")->assertOk();
        $this->assertSame(2, DocumentEvent::where('document_id', $document->id)->where('event', 'notified')->count());

        $this->postJson("/api/documents/{$draft->id}/notify")->assertStatus(409);
    }

    public function test_failed_notification_can_be_retried_immediately(): void
    {
        $this->fakeClicksign(['POST /envelopes/'.self::ENV.'/notifications' => Http::response('Service Unavailable', 503)]);
        $document = $this->sentDocument();

        $this->actingAsRole('admin');

        $this->postJson("/api/documents/{$document->id}/notify")->assertStatus(502)->assertJsonPath('reason', 'unavailable');
        $this->assertNull($document->refresh()->last_notified_at);
    }

    // ------------------------------------------------ 15-17. sincronização

    public function test_one_signature_does_not_finish_the_document(): void
    {
        $this->fakeClicksign([
            'GET /envelopes/'.self::ENV.'/documents/'.self::DOC.'/events' => $this->remoteEvents([
                ['name' => 'sign', 'data' => ['signer' => ['key' => self::SIGNER_A, 'email' => 'maria@example.com']], 'created' => '2026-09-22T10:00:00-03:00'],
                ['name' => 'upload', 'data' => [], 'created' => '2026-09-22T09:00:00-03:00'],
            ]),
        ]);
        $document = $this->sentDocument();

        $this->actingAsRole('attendant');

        $this->postJson("/api/documents/{$document->id}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_signed')
            ->assertJsonPath('data.signed_count', 1)
            ->assertJsonPath('data.signers_count', 2)
            ->assertJsonPath('data.signers.0.status', 'signed')
            ->assertJsonPath('data.signers.1.status', 'pending')
            ->assertJsonPath('data.has_signed_file', false);
    }

    public function test_all_signatures_and_closed_envelope_finish_the_document(): void
    {
        $this->fakeClicksign([
            'GET /envelopes/'.self::ENV => Http::response(['data' => ['id' => self::ENV, 'attributes' => ['status' => 'closed']]]),
            'GET /envelopes/'.self::ENV.'/documents/'.self::DOC.'/events' => $this->remoteEvents([
                ['name' => 'auto_close', 'data' => null, 'created' => '2026-09-22T11:00:01-03:00'],
                ['name' => 'sign', 'data' => ['signer' => ['key' => self::SIGNER_B]], 'created' => '2026-09-22T11:00:00-03:00'],
                ['name' => 'sign', 'data' => ['signer' => ['key' => self::SIGNER_A]], 'created' => '2026-09-22T10:00:00-03:00'],
            ]),
        ]);
        $document = $this->sentDocument();

        $this->actingAsRole('admin');

        $this->postJson("/api/documents/{$document->id}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.signed_count', 2)
            ->assertJsonPath('data.abilities.download_signed', true);

        $this->assertNotNull($document->refresh()->signed_at);

        // Sincronizar de novo não duplica o histórico.
        $this->postJson("/api/documents/{$document->id}/sync")->assertOk()->assertJsonPath('message', 'Tudo em dia: nenhuma alteração na Clicksign.');
        $this->assertSame(1, DocumentEvent::where('document_id', $document->id)->where('event', 'signed')->count());
        $this->assertSame(2, DocumentEvent::where('document_id', $document->id)->where('event', 'signer_signed')->count());
    }

    public function test_sync_recovers_a_send_whose_activation_actually_happened(): void
    {
        $this->fakeClicksign();
        $document = $this->sentDocument();
        $document->forceFill(['status' => DocumentStatus::IntegrationError, 'sent_at' => null, 'last_error' => 'timeout'])->save();

        $this->actingAsRole('admin');

        $this->postJson("/api/documents/{$document->id}/sync")->assertOk()->assertJsonPath('data.status', 'awaiting_signature');
        $document->refresh();
        $this->assertNull($document->last_error);
        $this->assertNotNull($document->sent_at);
    }

    // ------------------------------------------------ 18-20. webhook

    /** @return array<string, mixed> */
    private function webhookPayload(string $event, array $data = [], string $documentStatus = 'running'): array
    {
        return [
            'event' => ['name' => $event, 'data' => $data, 'occurred_at' => '2026-09-22T12:00:00-03:00'],
            'document' => ['key' => self::DOC, 'status' => $documentStatus, 'filename' => 'contrato.pdf'],
        ];
    }

    public function test_webhook_with_valid_hmac_updates_the_document(): void
    {
        $document = $this->sentDocument();

        $this->postWebhook($this->webhookPayload('sign', ['signer' => ['key' => self::SIGNER_A, 'email' => 'maria@example.com']]))
            ->assertOk()->assertJson(['received' => true]);

        $document->refresh()->load('signers');
        $this->assertSame(DocumentStatus::PartiallySigned, $document->status);
        $this->assertSame(DocumentSignerStatus::Signed, $document->signers[0]->status);

        $this->postWebhook($this->webhookPayload('auto_close', [], 'closed'))->assertOk();
        $this->assertSame(DocumentStatus::Signed, $document->refresh()->status);

        // Webhook não chama a API (resposta rápida, só banco local).
        Http::assertNothingSent();
    }

    public function test_webhook_with_invalid_or_missing_hmac_is_rejected(): void
    {
        $document = $this->sentDocument();
        $payload = $this->webhookPayload('cancel');

        $this->postWebhook($payload, 'sha256='.str_repeat('0', 64))->assertUnauthorized();
        $this->postWebhook($payload, '')->assertUnauthorized();

        // HMAC calculado sobre OUTRO corpo (JSON reformatado) também não vale.
        $this->postWebhook($payload, $this->signWebhook(json_encode($payload, JSON_PRETTY_PRINT)))->assertUnauthorized();

        $this->assertSame(DocumentStatus::AwaitingSignature, $document->refresh()->status);
        $this->assertSame(0, DocumentEvent::where('document_id', $document->id)->where('event', 'canceled')->count());
    }

    public function test_webhook_is_refused_when_secret_is_not_configured(): void
    {
        config(['services.clicksign.webhook_secret' => null]);
        $document = $this->sentDocument();

        $this->postWebhook($this->webhookPayload('cancel'))->assertStatus(503);
        $this->assertSame(DocumentStatus::AwaitingSignature, $document->refresh()->status);
    }

    public function test_duplicated_webhook_is_idempotent(): void
    {
        $document = $this->sentDocument();
        $payload = $this->webhookPayload('sign', ['signer' => ['key' => self::SIGNER_A]]);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, DocumentEvent::where('document_id', $document->id)->where('event', 'signer_signed')->count());
        $this->assertSame(DocumentStatus::PartiallySigned, $document->refresh()->status);
    }

    public function test_late_events_do_not_reopen_a_final_state(): void
    {
        $document = $this->sentDocument();

        $this->postWebhook($this->webhookPayload('cancel', [], 'canceled'))->assertOk();
        $this->postWebhook($this->webhookPayload('auto_close', [], 'closed'))->assertOk();
        $this->postWebhook($this->webhookPayload('sign', ['signer' => ['key' => self::SIGNER_A]]))->assertOk();

        $this->assertSame(DocumentStatus::Canceled, $document->refresh()->status);
    }

    public function test_webhook_for_unknown_document_is_acknowledged_and_ignored(): void
    {
        $payload = $this->webhookPayload('sign');
        $payload['document']['key'] = 'nao-existe-no-snapmanager';

        $this->postWebhook($payload)->assertOk();
        $this->assertSame(0, DocumentEvent::count());
    }

    public function test_webhook_accepts_document_as_a_list(): void
    {
        $document = $this->sentDocument();
        $payload = $this->webhookPayload('document_closed', [], 'closed');
        $payload['document'] = [$payload['document']];

        $this->postWebhook($payload)->assertOk();
        $this->assertSame(DocumentStatus::Signed, $document->refresh()->status);
    }

    public function test_webhook_and_sync_do_not_duplicate_each_other(): void
    {
        $document = $this->sentDocument();
        $this->postWebhook($this->webhookPayload('sign', ['signer' => ['key' => self::SIGNER_A]]))->assertOk();

        $this->fakeClicksign([
            'GET /envelopes/'.self::ENV.'/documents/'.self::DOC.'/events' => $this->remoteEvents([
                ['name' => 'sign', 'data' => ['signer' => ['key' => self::SIGNER_A]], 'created' => '2026-09-22T12:00:00-03:00'],
            ]),
        ]);
        $this->actingAsRole('admin');
        $this->postJson("/api/documents/{$document->id}/sync")->assertOk();

        $this->assertSame(1, DocumentEvent::where('document_id', $document->id)->where('event', 'signer_signed')->count());
    }

    // ------------------------------------------------ 21. cancelamento

    public function test_admin_cancels_the_signature_process(): void
    {
        $this->fakeClicksign();
        $document = $this->sentDocument();

        $this->actingAsRole('admin');

        $this->postJson("/api/documents/{$document->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        // Cancelamento na API 3.0 é no DOCUMENTO, não no envelope.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/envelopes/'.self::ENV.'/documents/'.self::DOC)
            && $r->data() === ['data' => ['type' => 'documents', 'id' => self::DOC, 'attributes' => ['status' => 'canceled']]]);

        // Não dá para cancelar de novo, nem cancelar rascunho.
        $this->postJson("/api/documents/{$document->id}/cancel")->assertStatus(409);
    }

    // ------------------------------------------------ 22. download do assinado

    public function test_download_signed_goes_through_laravel_without_leaking_the_token(): void
    {
        $this->fakeClicksign();
        $document = $this->sentDocument();
        $document->forceFill(['status' => DocumentStatus::Signed, 'signed_at' => now()])->save();

        $this->actingAsRole('attendant');

        $response = $this->get("/api/documents/{$document->id}/download-signed")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());

        // O link pré-assinado é baixado SEM o Access Token.
        Http::assertSent(fn (Request $r) => $r->url() === self::SIGNED_URL && $r->header('Authorization') === []);

        // A cópia local evita novas chamadas nos próximos downloads.
        $document->refresh();
        Storage::disk('local')->assertExists($document->signed_file_path);
        $callsBefore = count(Http::recorded());
        $this->get("/api/documents/{$document->id}/download-signed")->assertOk();
        $this->assertCount($callsBefore, Http::recorded());
    }

    public function test_download_signed_is_unavailable_before_completion(): void
    {
        $document = $this->sentDocument();

        $this->actingAsRole('admin');

        $this->getJson("/api/documents/{$document->id}/download-signed")->assertStatus(409);
        Http::assertNothingSent();
    }

    // ------------------------------------------------ edição e exclusão

    public function test_only_drafts_can_be_edited_or_deleted(): void
    {
        $draft = $this->createDocument();
        $sent = $this->sentDocument();

        $this->actingAsRole('admin');

        $this->post("/api/documents/{$draft->id}", ['_method' => 'PUT', 'title' => 'Novo título', 'file' => $this->pdf('novo.pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.title', 'Novo título');
        $this->assertDatabaseHas('document_events', ['document_id' => $draft->id, 'event' => 'file_replaced']);

        $this->putJson("/api/documents/{$sent->id}", ['title' => 'Mudança'])->assertStatus(409);
        $this->deleteJson("/api/documents/{$sent->id}")->assertStatus(409);

        $this->deleteJson("/api/documents/{$draft->id}")->assertNoContent();
        $this->assertSoftDeleted('documents', ['id' => $draft->id]);
    }

    // ------------------------------------------------ listagem

    public function test_list_filters_and_search(): void
    {
        $this->createDocument(['title' => 'Contrato Casamento Ana']);
        $this->sentDocument();

        $this->actingAsRole('attendant');

        $this->getJson('/api/documents?status=draft')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Contrato Casamento Ana');
        $this->getJson('/api/documents?status=awaiting')->assertJsonCount(1, 'data');
        $this->getJson('/api/documents?search=casamento')->assertJsonCount(1, 'data');
        $this->getJson('/api/documents?search=maria cliente')->assertJsonCount(2, 'data');

        // Listagem não carrega histórico nem expõe o Access Token.
        $response = $this->getJson('/api/documents')->assertJsonMissingPath('data.0.events.0');
        $this->assertStringNotContainsString(self::FAKE_TOKEN, $response->getContent());
    }
}
