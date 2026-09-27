<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Comando de homologação clicksign:test-envelope.
 *
 * O fake abaixo simula a Clicksign roteando por "MÉTODO caminho", o que
 * permite conferir a ORDEM exata das chamadas contra a receita oficial. As
 * confirmações do comando são respondidas explicitamente em cada teste —
 * nada roda por padrão.
 */
class ClicksignTestEnvelopeCommandTest extends TestCase
{
    private const FAKE_TOKEN = 'token-ficticio-de-teste-0000-1111';

    private const ENVELOPE_ID = '11111111-1111-4111-8111-111111111111';

    private const DOCUMENT_ID = '22222222-2222-4222-8222-222222222222';

    private const SIGNER_ID = '33333333-3333-4333-8333-333333333333';

    private const SIGNER_EMAIL = 'signatario@example.com';

    private const Q_CREATE = 'Criar o envelope de teste (rascunho) no Sandbox?';

    private const Q_ACTIVATE = 'ATIVAR o envelope agora (iniciar o processo de assinatura)?';

    private const Q_NOTIFY = 'Enviar agora a solicitação de assinatura para '.self::SIGNER_EMAIL.'?';

    private const Q_DELETE = 'Excluir o envelope rascunho '.self::ENVELOPE_ID.'?';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.clicksign.base_url' => 'https://sandbox.clicksign.com/api/v3',
            'services.clicksign.access_token' => self::FAKE_TOKEN,
        ]);
    }

    /**
     * Simula a Clicksign. `$overrides` troca a resposta de uma rota
     * ("POST /envelopes/{id}/signers" etc.) para provocar falhas.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeClicksign(array $overrides = []): void
    {
        $status = 'draft';
        $env = '/envelopes/'.self::ENVELOPE_ID;

        Http::fake(function (Request $request) use (&$status, $overrides, $env) {
            $key = $request->method().' '.substr(parse_url($request->url(), PHP_URL_PATH), strlen('/api/v3'));

            if (array_key_exists($key, $overrides)) {
                return $overrides[$key];
            }

            return match ($key) {
                'POST /envelopes' => Http::response(['data' => ['id' => self::ENVELOPE_ID, 'type' => 'envelopes', 'attributes' => ['status' => 'draft']]], 201),
                "POST {$env}/documents" => Http::response(['data' => ['id' => self::DOCUMENT_ID, 'type' => 'documents', 'attributes' => ['status' => 'draft']]], 201),
                "POST {$env}/signers" => Http::response(['data' => ['id' => self::SIGNER_ID, 'type' => 'signers']], 201),
                "POST {$env}/requirements" => Http::response(['data' => ['id' => 'req-'.uniqid(), 'type' => 'requirements']], 201),
                "PATCH {$env}" => (function () use (&$status) {
                    $status = 'running';

                    return Http::response(['data' => ['id' => self::ENVELOPE_ID, 'type' => 'envelopes', 'attributes' => ['status' => 'running']]], 200);
                })(),
                "POST {$env}/notifications" => Http::response(['data' => ['id' => 'notif-1', 'type' => 'notifications']], 200),
                "GET {$env}" => Http::response(['data' => ['id' => self::ENVELOPE_ID, 'type' => 'envelopes', 'attributes' => ['status' => $status]]]),
                "GET {$env}/signers" => Http::response(['data' => [['id' => self::SIGNER_ID]]]),
                "GET {$env}/requirements" => Http::response(['data' => [['id' => 'a'], ['id' => 'b']]]),
                "DELETE {$env}" => Http::response(null, 204),
                default => Http::response(['errors' => [['detail' => "rota não simulada: {$key}"]]], 404),
            };
        });
    }

    /** @return array<int, string> "MÉTODO caminho" de cada requisição, em ordem. */
    private function sentCalls(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->method().' '.substr(parse_url($pair[0]->url(), PHP_URL_PATH), strlen('/api/v3')))
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private function signerOptions(): array
    {
        return ['--name' => 'Pessoa Teste', '--email' => self::SIGNER_EMAIL];
    }

    // --- Travas de segurança -------------------------------------------------

    public function test_no_interaction_creates_nothing(): void
    {
        $this->fakeClicksign();

        // Artisan::call (e não $this->artisan): o mock de console do
        // $this->artisan ignora --no-interaction. Aqui a entrada é realmente
        // não interativa, então toda confirmação cai no padrão — "não".
        $exitCode = Artisan::call('clicksign:test-envelope', [...$this->signerOptions(), '--no-interaction' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Nenhum recurso foi criado', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_declining_the_first_confirmation_creates_nothing(): void
    {
        $this->fakeClicksign();

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsConfirmation(self::Q_CREATE, 'no')
            ->expectsOutputToContain('Nenhum recurso foi criado')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_refuses_to_run_outside_the_sandbox(): void
    {
        config(['services.clicksign.base_url' => 'https://app.clicksign.com/api/v3']);
        $this->fakeClicksign();

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsOutputToContain('só roda no Sandbox')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_refuses_when_not_configured(): void
    {
        config(['services.clicksign.access_token' => null]);
        $this->fakeClicksign();

        $this->artisan('clicksign:test-envelope', $this->signerOptions())->assertFailed();

        Http::assertNothingSent();
    }

    public function test_rejects_invalid_signer_email(): void
    {
        $this->fakeClicksign();

        $this->artisan('clicksign:test-envelope', ['--name' => 'Pessoa Teste', '--email' => 'nao-e-email'])
            ->expectsOutputToContain('E-mail inválido')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_rejects_signer_name_without_surname(): void
    {
        $this->fakeClicksign();

        $this->artisan('clicksign:test-envelope', ['--name' => 'Pessoa', '--email' => self::SIGNER_EMAIL])
            ->expectsOutputToContain('nome e sobrenome')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_asks_for_signer_when_options_are_missing(): void
    {
        $this->fakeClicksign();

        $this->artisan('clicksign:test-envelope')
            ->expectsQuestion('Nome completo do signatário de teste (nome e sobrenome)', 'Pessoa Teste')
            ->expectsQuestion('E-mail do signatário (um e-mail seu, autorizado para o teste)', self::SIGNER_EMAIL)
            ->expectsConfirmation(self::Q_CREATE, 'no')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    // --- Fluxo completo ------------------------------------------------------

    public function test_full_flow_follows_the_official_order(): void
    {
        $this->fakeClicksign();

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsConfirmation(self::Q_CREATE, 'yes')
            ->expectsConfirmation(self::Q_ACTIVATE, 'yes')
            ->expectsConfirmation(self::Q_NOTIFY, 'yes')
            ->expectsOutputToContain(self::ENVELOPE_ID)
            ->expectsOutputToContain('running (Em andamento)')
            ->expectsOutputToContain('a API 3.0 não devolve URL')
            ->doesntExpectOutputToContain(self::FAKE_TOKEN)
            ->assertSuccessful();

        $env = '/envelopes/'.self::ENVELOPE_ID;

        $this->assertSame([
            'POST /envelopes',
            "POST {$env}/documents",
            "POST {$env}/signers",
            "POST {$env}/requirements",
            "POST {$env}/requirements",
            "PATCH {$env}",
            "POST {$env}/notifications",
            // resumo
            "GET {$env}",
            "GET {$env}/signers",
            "GET {$env}/requirements",
        ], $this->sentCalls());
    }

    public function test_upload_sends_the_generated_pdf_and_signer_is_not_asked_for_cpf(): void
    {
        $this->fakeClicksign();

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsConfirmation(self::Q_CREATE, 'yes')
            ->expectsConfirmation(self::Q_ACTIVATE, 'no')
            ->expectsConfirmation(self::Q_DELETE, 'no')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/documents')) {
                return false;
            }

            $content = $request['data']['attributes']['content_base64'];
            $prefix = 'data:application/pdf;base64,';

            return str_starts_with($content, $prefix)
                && str_starts_with(base64_decode(substr($content, strlen($prefix))), '%PDF-')
                && str_ends_with($request['data']['attributes']['filename'], '.pdf');
        });

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/signers')
            && $request->method() === 'POST'
            && $request['data']['attributes'] === [
                'name' => 'Pessoa Teste',
                'email' => self::SIGNER_EMAIL,
                'has_documentation' => false,
            ]);
    }

    public function test_declining_activation_keeps_a_draft_and_sends_nothing_to_the_signer(): void
    {
        $this->fakeClicksign();

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsConfirmation(self::Q_CREATE, 'yes')
            ->expectsConfirmation(self::Q_ACTIVATE, 'no')
            ->expectsConfirmation(self::Q_DELETE, 'no')
            ->expectsOutputToContain('nada foi enviado')
            ->expectsOutputToContain('draft (Rascunho)')
            ->assertSuccessful();

        $calls = $this->sentCalls();
        $this->assertNotContains('PATCH /envelopes/'.self::ENVELOPE_ID, $calls);
        $this->assertNotContains('POST /envelopes/'.self::ENVELOPE_ID.'/notifications', $calls);
        $this->assertNotContains('DELETE /envelopes/'.self::ENVELOPE_ID, $calls);
    }

    public function test_declining_activation_can_delete_the_draft(): void
    {
        $this->fakeClicksign();

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsConfirmation(self::Q_CREATE, 'yes')
            ->expectsConfirmation(self::Q_ACTIVATE, 'no')
            ->expectsConfirmation(self::Q_DELETE, 'yes')
            ->expectsOutputToContain('excluído')
            ->assertSuccessful();

        $this->assertContains('DELETE /envelopes/'.self::ENVELOPE_ID, $this->sentCalls());
    }

    public function test_activation_without_notification(): void
    {
        $this->fakeClicksign();

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsConfirmation(self::Q_CREATE, 'yes')
            ->expectsConfirmation(self::Q_ACTIVATE, 'yes')
            ->expectsConfirmation(self::Q_NOTIFY, 'no')
            ->expectsOutputToContain('Notificação não enviada')
            ->assertSuccessful();

        $calls = $this->sentCalls();
        $this->assertContains('PATCH /envelopes/'.self::ENVELOPE_ID, $calls);
        $this->assertNotContains('POST /envelopes/'.self::ENVELOPE_ID.'/notifications', $calls);
    }

    // --- Falhas --------------------------------------------------------------

    public function test_authentication_error_on_the_first_step_leaves_nothing_behind(): void
    {
        $this->fakeClicksign(['POST /envelopes' => Http::response(['errors' => [['title' => 'Unauthorized']]], 401)]);

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsConfirmation(self::Q_CREATE, 'yes')
            ->expectsOutputToContain('Falha na etapa "criar envelope"')
            ->expectsOutputToContain('unauthorized')
            ->expectsOutputToContain('Nenhum recurso ficou criado')
            ->doesntExpectOutputToContain(self::FAKE_TOKEN)
            ->assertFailed();

        $this->assertSame(['POST /envelopes'], $this->sentCalls());
    }

    public function test_partial_failure_reports_the_step_and_cleans_up_the_draft(): void
    {
        $env = '/envelopes/'.self::ENVELOPE_ID;

        $this->fakeClicksign(["POST {$env}/signers" => Http::response(['errors' => [[
            'source' => ['pointer' => '/data/attributes/name'],
            'detail' => 'name - deve conter ao menos duas palavras',
        ]]], 422)]);

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsConfirmation(self::Q_CREATE, 'yes')
            ->expectsOutputToContain('Falha na etapa "cadastrar signatário": A Clicksign recusou a requisição (HTTP 422): name - deve conter ao menos duas palavras')
            ->expectsOutputToContain(self::DOCUMENT_ID)
            ->expectsConfirmation(self::Q_DELETE, 'yes')
            ->assertFailed();

        $this->assertSame([
            'POST /envelopes',
            "POST {$env}/documents",
            "POST {$env}/signers",
            // limpeza: confirma que ainda é rascunho e exclui
            "GET {$env}",
            "DELETE {$env}",
        ], $this->sentCalls());
    }

    public function test_partial_failure_can_keep_the_draft(): void
    {
        $env = '/envelopes/'.self::ENVELOPE_ID;

        $this->fakeClicksign(["POST {$env}/requirements" => Http::response('Bad Gateway', 502)]);

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsConfirmation(self::Q_CREATE, 'yes')
            ->expectsOutputToContain('Falha na etapa "requisito de qualificação"')
            ->expectsOutputToContain('unavailable')
            ->expectsConfirmation(self::Q_DELETE, 'no')
            ->expectsOutputToContain('Rascunho mantido')
            ->assertFailed();

        $this->assertNotContains("DELETE {$env}", $this->sentCalls());
        $this->assertNotContains("PATCH {$env}", $this->sentCalls());
    }

    public function test_activation_failure_offers_draft_cleanup(): void
    {
        $env = '/envelopes/'.self::ENVELOPE_ID;

        $this->fakeClicksign(["PATCH {$env}" => Http::response(['errors' => [['detail' => 'signatário sem requisito de autenticação']]], 422)]);

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsConfirmation(self::Q_CREATE, 'yes')
            ->expectsConfirmation(self::Q_ACTIVATE, 'yes')
            ->expectsOutputToContain('Falha na etapa "ativar envelope": A Clicksign recusou a requisição (HTTP 422): signatário sem requisito de autenticação')
            ->expectsConfirmation(self::Q_DELETE, 'yes')
            ->assertFailed();

        $this->assertContains("DELETE {$env}", $this->sentCalls());
    }

    public function test_notification_failure_does_not_undo_the_active_envelope(): void
    {
        $env = '/envelopes/'.self::ENVELOPE_ID;

        $this->fakeClicksign(["POST {$env}/notifications" => Http::response('Service Unavailable', 503)]);

        $this->artisan('clicksign:test-envelope', $this->signerOptions())
            ->expectsConfirmation(self::Q_CREATE, 'yes')
            ->expectsConfirmation(self::Q_ACTIVATE, 'yes')
            ->expectsConfirmation(self::Q_NOTIFY, 'yes')
            ->expectsOutputToContain('Falha ao notificar')
            ->expectsOutputToContain('continua ativo')
            ->assertSuccessful();

        $this->assertNotContains("DELETE {$env}", $this->sentCalls());
    }
}
