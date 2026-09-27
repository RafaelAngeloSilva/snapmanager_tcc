<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regressão do bug "Finalizado em 08:57 / Enviado em 11:54".
 *
 * Reproduz o caso real da homologação: envio às 14:54 UTC (11:54 em
 * Brasília) e assinatura informada pela Clicksign como
 * "2026-09-22T11:57:22-03:00". Antes da correção, 11:57 era gravado como se
 * fosse UTC e a tela mostrava 08:57 — antes do envio.
 */
class DocumentTimestampsTest extends TestCase
{
    use RefreshDatabase;

    private const ENV = 'env-1';

    private const DOC = 'doc-1';

    private const SIGNER = 'signer-1';

    private const SECRET = 'segredo-ficticio-do-webhook';

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.clicksign.base_url' => 'https://sandbox.clicksign.com/api/v3',
            'services.clicksign.access_token' => 'token-ficticio',
            'services.clicksign.webhook_secret' => self::SECRET,
        ]);

        $document = new Document([
            'client_id' => Client::factory()->create()->id,
            'title' => 'Contrato',
            'type' => 'photoshoot',
            'original_file_path' => 'documents/x.pdf',
            'original_file_name' => 'x.pdf',
            'original_file_size' => 10,
        ]);
        $document->forceFill([
            'status' => DocumentStatus::AwaitingSignature,
            'clicksign_envelope_id' => self::ENV,
            'clicksign_document_id' => self::DOC,
            // Gravado por now() em UTC, como no caso real.
            'sent_at' => Carbon::parse('2026-09-22 14:54:55', 'UTC'),
        ])->save();

        $signer = $document->signers()->create(['name' => 'Pessoa Teste', 'channel' => 'email', 'email' => 'p@example.com']);
        $signer->forceFill(['clicksign_signer_id' => self::SIGNER])->save();

        $this->document = $document;
    }

    private function assertStoredAsUtc(string $expected, string $table, int $id, string $column): void
    {
        $this->assertSame($expected, (string) DB::table($table)->where('id', $id)->value($column));
    }

    public function test_sync_stores_clicksign_times_in_utc(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return str_ends_with($path, '/events')
                ? Http::response(['data' => [
                    ['attributes' => ['name' => 'document_closed', 'data' => [], 'created' => '2026-09-22T11:57:43.764-03:00']],
                    ['attributes' => ['name' => 'auto_close', 'data' => null, 'created' => '2026-09-22T11:57:22.084-03:00']],
                    ['attributes' => ['name' => 'sign', 'data' => ['signer' => ['key' => self::SIGNER]], 'created' => '2026-09-22T11:57:21.832-03:00']],
                ]])
                : Http::response(['data' => ['id' => self::ENV, 'attributes' => ['status' => 'closed']]]);
        });

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->postJson("/api/documents/{$this->document->id}/sync")->assertOk();

        // 11:57:22 em Brasília = 14:57:22 UTC. Antes da correção: "11:57:22".
        $this->assertStoredAsUtc('2026-09-22 14:57:22', 'documents', $this->document->id, 'signed_at');
        $this->assertStoredAsUtc('2026-09-22 14:57:21', 'document_signers', $this->document->signers()->first()->id, 'signed_at');

        // A API devolve o mesmo instante em ISO-8601 UTC — o navegador
        // converte uma única vez para o horário local.
        $response->assertJsonPath('data.signed_at', '2026-09-22T14:57:22.000000Z');
        $response->assertJsonPath('data.sent_at', '2026-09-22T14:54:55.000000Z');

        // E a finalização nunca vem antes do envio.
        $this->assertTrue(Carbon::parse($response->json('data.signed_at'))->gte(Carbon::parse($response->json('data.sent_at'))));
    }

    public function test_webhook_stores_clicksign_times_in_utc(): void
    {
        $post = function (array $payload) {
            $body = json_encode($payload);

            return $this->call('POST', '/api/webhooks/clicksign', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_CONTENT_HMAC' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
            ], $body);
        };

        $post([
            'event' => ['name' => 'sign', 'data' => ['signer' => ['key' => self::SIGNER]], 'occurred_at' => '2026-09-22T11:57:21.832-03:00'],
            'document' => ['key' => self::DOC],
        ])->assertOk();

        $post([
            'event' => ['name' => 'auto_close', 'data' => null, 'occurred_at' => '2026-09-22T11:57:22.084-03:00'],
            'document' => ['key' => self::DOC, 'status' => 'closed'],
        ])->assertOk();

        $this->assertStoredAsUtc('2026-09-22 14:57:22', 'documents', $this->document->id, 'signed_at');
        $this->assertStoredAsUtc('2026-09-22 14:57:21', 'document_signers', $this->document->signers()->first()->id, 'signed_at');
        Http::assertNothingSent();
    }

    public function test_repair_command_recomputes_legacy_times_from_clicksign_events(): void
    {
        // Estado deixado pelo bug: horário de Brasília gravado como UTC.
        $this->document->forceFill(['status' => DocumentStatus::Signed, 'signed_at' => Carbon::parse('2026-09-22 11:57:22', 'UTC')])->save();
        $signer = $this->document->signers()->first();
        $signer->forceFill(['status' => 'signed', 'signed_at' => Carbon::parse('2026-09-22 11:57:21', 'UTC')])->save();

        Http::fake(['*' => Http::response(['data' => [
            ['attributes' => ['name' => 'document_closed', 'data' => [], 'created' => '2026-09-22T11:57:43.764-03:00']],
            ['attributes' => ['name' => 'auto_close', 'data' => null, 'created' => '2026-09-22T11:57:22.084-03:00']],
            ['attributes' => ['name' => 'sign', 'data' => ['signer' => ['key' => self::SIGNER]], 'created' => '2026-09-22T11:57:21.832-03:00']],
        ]])]);

        // Simulação (padrão): mostra e não grava.
        $this->artisan('documents:repair-signed-times')
            ->expectsOutputToContain('Simulação: nada foi gravado')
            ->assertSuccessful();
        $this->assertStoredAsUtc('2026-09-22 11:57:22', 'documents', $this->document->id, 'signed_at');

        // Recusar a confirmação também não grava.
        $this->artisan('documents:repair-signed-times', ['--apply' => true])
            ->expectsConfirmation('Gravar as correções acima?', 'no')
            ->assertSuccessful();
        $this->assertStoredAsUtc('2026-09-22 11:57:22', 'documents', $this->document->id, 'signed_at');

        $this->artisan('documents:repair-signed-times', ['--apply' => true])
            ->expectsConfirmation('Gravar as correções acima?', 'yes')
            ->expectsOutputToContain('2 horário(s) corrigido(s)')
            ->assertSuccessful();

        $this->assertStoredAsUtc('2026-09-22 14:57:22', 'documents', $this->document->id, 'signed_at');
        $this->assertStoredAsUtc('2026-09-22 14:57:21', 'document_signers', $signer->id, 'signed_at');
        $this->assertDatabaseHas('document_events', ['document_id' => $this->document->id, 'event' => 'synced']);

        // Idempotente: rodar de novo não muda nada.
        $this->artisan('documents:repair-signed-times', ['--apply' => true])
            ->expectsOutputToContain('Nada a corrigir')
            ->assertSuccessful();
    }

    public function test_times_already_in_utc_are_not_shifted_twice(): void
    {
        Http::fake(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/events')
            ? Http::response(['data' => [
                ['attributes' => ['name' => 'auto_close', 'data' => null, 'created' => '2026-09-22T14:57:22Z']],
            ]])
            : Http::response(['data' => ['id' => self::ENV, 'attributes' => ['status' => 'closed']]]));

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson("/api/documents/{$this->document->id}/sync")->assertOk();

        $this->assertStoredAsUtc('2026-09-22 14:57:22', 'documents', $this->document->id, 'signed_at');
    }
}
