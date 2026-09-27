<?php

namespace Tests\Feature;

use App\Enums\ClicksignAuthMethod;
use App\Enums\ClicksignErrorReason;
use App\Enums\ClicksignSignerRole;
use App\Exceptions\ClicksignException;
use App\Services\ClicksignService;
use App\Support\ClicksignTestContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Operações de envelope do ClicksignService. Tudo contra Http::fake(), com
 * token fictício e preventStrayRequests(): nenhum envelope real é criado.
 *
 * Cada teste confere método, caminho e corpo JSON:API contra o que a
 * documentação oficial da API 3.0 especifica.
 */
class ClicksignEnvelopeFlowTest extends TestCase
{
    private const FAKE_TOKEN = 'token-ficticio-de-teste-0000-1111';

    private const BASE_URL = 'https://sandbox.clicksign.com/api/v3';

    private const ENVELOPE_ID = '11111111-1111-4111-8111-111111111111';

    private const DOCUMENT_ID = '22222222-2222-4222-8222-222222222222';

    private const SIGNER_ID = '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.clicksign.base_url' => self::BASE_URL,
            'services.clicksign.access_token' => self::FAKE_TOKEN,
            'services.clicksign.timeout' => 5,
        ]);
    }

    private function service(): ClicksignService
    {
        return app(ClicksignService::class);
    }

    private function captureFailure(callable $call): ClicksignException
    {
        try {
            $call();
        } catch (ClicksignException $e) {
            return $e;
        }

        $this->fail('Era esperada uma ClicksignException.');
    }

    /** Garante que a requisição carrega a autenticação e os headers JSON:API da etapa 1. */
    private function sentWithClicksignAuth(Request $request): bool
    {
        return $request->header('Authorization') === [self::FAKE_TOKEN]
            && $request->header('Content-Type') === [ClicksignService::JSON_API_MEDIA_TYPE]
            && $request->header('Accept') === [ClicksignService::JSON_API_MEDIA_TYPE];
    }

    public function test_create_envelope(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['id' => self::ENVELOPE_ID, 'type' => 'envelopes', 'attributes' => ['name' => 'Teste', 'status' => 'draft']],
        ], 201)]);

        $envelope = $this->service()->createEnvelope('Teste', ['locale' => 'pt-BR']);

        $this->assertSame(self::ENVELOPE_ID, $envelope['id']);
        $this->assertSame('draft', $envelope['attributes']['status']);

        Http::assertSent(fn (Request $request) => $this->sentWithClicksignAuth($request)
            && $request->method() === 'POST'
            && $request->url() === self::BASE_URL.'/envelopes'
            && $request->data() === ['data' => [
                'type' => 'envelopes',
                'attributes' => ['name' => 'Teste', 'locale' => 'pt-BR'],
            ]]);
    }

    public function test_add_document_sends_base64_as_data_uri(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['id' => self::DOCUMENT_ID, 'type' => 'documents', 'attributes' => ['status' => 'draft']],
        ], 201)]);

        $document = $this->service()->addDocument(self::ENVELOPE_ID, 'contrato.pdf', '%PDF-1.4 conteudo');

        $this->assertSame(self::DOCUMENT_ID, $document['id']);

        Http::assertSent(fn (Request $request) => $this->sentWithClicksignAuth($request)
            && $request->method() === 'POST'
            && $request->url() === self::BASE_URL.'/envelopes/'.self::ENVELOPE_ID.'/documents'
            && $request['data']['type'] === 'documents'
            && $request['data']['attributes']['filename'] === 'contrato.pdf'
            // Formato exigido pela documentação: data:<mimetype>;base64,<data>
            && $request['data']['attributes']['content_base64'] === 'data:application/pdf;base64,'.base64_encode('%PDF-1.4 conteudo'));
    }

    public function test_add_signer(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['id' => self::SIGNER_ID, 'type' => 'signers', 'attributes' => ['name' => 'Pessoa Teste']],
        ], 201)]);

        $signer = $this->service()->addSigner(self::ENVELOPE_ID, [
            'name' => 'Pessoa Teste',
            'email' => 'signatario@example.com',
            'has_documentation' => false,
        ]);

        $this->assertSame(self::SIGNER_ID, $signer['id']);

        Http::assertSent(fn (Request $request) => $this->sentWithClicksignAuth($request)
            && $request->method() === 'POST'
            && $request->url() === self::BASE_URL.'/envelopes/'.self::ENVELOPE_ID.'/signers'
            && $request->data() === ['data' => [
                'type' => 'signers',
                'attributes' => ['name' => 'Pessoa Teste', 'email' => 'signatario@example.com', 'has_documentation' => false],
            ]]);
    }

    public function test_qualification_requirement(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'req-1', 'type' => 'requirements']], 201)]);

        $this->service()->addQualificationRequirement(self::ENVELOPE_ID, self::DOCUMENT_ID, self::SIGNER_ID, ClicksignSignerRole::Party);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::BASE_URL.'/envelopes/'.self::ENVELOPE_ID.'/requirements'
            && $request->data() === ['data' => [
                'type' => 'requirements',
                'attributes' => ['action' => 'agree', 'role' => 'party'],
                'relationships' => [
                    'document' => ['data' => ['type' => 'documents', 'id' => self::DOCUMENT_ID]],
                    'signer' => ['data' => ['type' => 'signers', 'id' => self::SIGNER_ID]],
                ],
            ]]);
    }

    public function test_authentication_requirement(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'req-2', 'type' => 'requirements']], 201)]);

        $this->service()->addAuthenticationRequirement(self::ENVELOPE_ID, self::DOCUMENT_ID, self::SIGNER_ID, ClicksignAuthMethod::Email);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::BASE_URL.'/envelopes/'.self::ENVELOPE_ID.'/requirements'
            && $request->data() === ['data' => [
                'type' => 'requirements',
                'attributes' => ['action' => 'provide_evidence', 'auth' => 'email'],
                'relationships' => [
                    'document' => ['data' => ['type' => 'documents', 'id' => self::DOCUMENT_ID]],
                    'signer' => ['data' => ['type' => 'signers', 'id' => self::SIGNER_ID]],
                ],
            ]]);
    }

    public function test_activate_envelope(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['id' => self::ENVELOPE_ID, 'type' => 'envelopes', 'attributes' => ['status' => 'running']],
        ], 200)]);

        $envelope = $this->service()->activateEnvelope(self::ENVELOPE_ID);

        $this->assertSame('running', $envelope['attributes']['status']);

        Http::assertSent(fn (Request $request) => $this->sentWithClicksignAuth($request)
            && $request->method() === 'PATCH'
            && $request->url() === self::BASE_URL.'/envelopes/'.self::ENVELOPE_ID
            && $request->data() === ['data' => [
                'type' => 'envelopes',
                'id' => self::ENVELOPE_ID,
                'attributes' => ['status' => 'running'],
            ]]);
    }

    public function test_notify_envelope_sends_empty_attributes_as_object(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'notif-1', 'type' => 'notifications']], 200)]);

        $this->service()->notifyEnvelope(self::ENVELOPE_ID);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::BASE_URL.'/envelopes/'.self::ENVELOPE_ID.'/notifications'
            // JSON:API: "attributes" precisa ser objeto, não lista vazia.
            && str_contains($request->body(), '"attributes":{}'));
    }

    public function test_read_operations(): void
    {
        Http::fake([
            'sandbox.clicksign.com/api/v3/envelopes/'.self::ENVELOPE_ID.'/signers' => Http::response(['data' => [['id' => self::SIGNER_ID]]]),
            'sandbox.clicksign.com/api/v3/envelopes/'.self::ENVELOPE_ID.'/requirements' => Http::response(['data' => [['id' => 'a'], ['id' => 'b']]]),
            'sandbox.clicksign.com/api/v3/envelopes/'.self::ENVELOPE_ID => Http::response(['data' => ['id' => self::ENVELOPE_ID, 'attributes' => ['status' => 'running']]]),
        ]);

        $service = $this->service();

        $this->assertSame('running', $service->getEnvelope(self::ENVELOPE_ID)['attributes']['status']);
        $this->assertCount(1, $service->listSigners(self::ENVELOPE_ID));
        $this->assertCount(2, $service->listRequirements(self::ENVELOPE_ID));

        Http::assertSentCount(3);
    }

    public function test_delete_draft_envelope(): void
    {
        Http::fake(['*' => Http::response(null, 204)]);

        $this->service()->deleteEnvelope(self::ENVELOPE_ID);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === self::BASE_URL.'/envelopes/'.self::ENVELOPE_ID);
    }

    public function test_ids_cannot_alter_the_request_path(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->service()->getEnvelope('../../users?x=1');

        Http::assertSent(fn (Request $request) => $request->url() === self::BASE_URL.'/envelopes/..%2F..%2Fusers%3Fx%3D1');
    }

    public function test_authentication_error_during_flow(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['title' => 'Unauthorized']]], 401)]);

        $e = $this->captureFailure(fn () => $this->service()->createEnvelope('Teste'));

        $this->assertSame(ClicksignErrorReason::Unauthorized, $e->reason);
        $this->assertStringNotContainsString(self::FAKE_TOKEN, $e->getMessage());
    }

    public function test_validation_error_exposes_the_api_detail(): void
    {
        // Formato de erro de validação documentado para o upload.
        Http::fake(['*' => Http::response(['errors' => [[
            'code' => 'bad_request',
            'status' => '422',
            'source' => ['pointer' => '/data/attributes/filename'],
            'detail' => 'filename não está em um formato válido',
        ]]], 422)]);

        $e = $this->captureFailure(fn () => $this->service()->addDocument(self::ENVELOPE_ID, 'sem-extensao', '%PDF'));

        $this->assertSame(ClicksignErrorReason::RequestFailed, $e->reason);
        $this->assertSame(422, $e->status);
        $this->assertStringContainsString('filename não está em um formato válido', $e->getMessage());
    }

    public function test_validation_error_does_not_echo_the_uploaded_file(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['detail' => 'content - não pode ficar em branco']]], 422)]);

        $contents = '%PDF-conteudo-secreto-do-contrato';
        $e = $this->captureFailure(fn () => $this->service()->addDocument(self::ENVELOPE_ID, 'a.pdf', $contents));

        $this->assertStringNotContainsString(base64_encode($contents), $e->getMessage());
    }

    public function test_unavailability_during_flow(): void
    {
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);

        $e = $this->captureFailure(fn () => $this->service()->addSigner(self::ENVELOPE_ID, ['name' => 'Pessoa Teste']));

        $this->assertSame(ClicksignErrorReason::Unavailable, $e->reason);
        $this->assertSame(503, $e->status);
    }

    public function test_connection_failure_during_activation(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $e = $this->captureFailure(fn () => $this->service()->activateEnvelope(self::ENVELOPE_ID));

        $this->assertSame(ClicksignErrorReason::Unavailable, $e->reason);
        $this->assertNull($e->getPrevious());
    }

    public function test_token_is_never_in_url_or_body_of_any_operation(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'x']], 200)]);

        $service = $this->service();
        $service->createEnvelope('Teste');
        $service->addDocument(self::ENVELOPE_ID, 'a.pdf', '%PDF');
        $service->addSigner(self::ENVELOPE_ID, ['name' => 'Pessoa Teste']);
        $service->addQualificationRequirement(self::ENVELOPE_ID, self::DOCUMENT_ID, self::SIGNER_ID);
        $service->addAuthenticationRequirement(self::ENVELOPE_ID, self::DOCUMENT_ID, self::SIGNER_ID);
        $service->activateEnvelope(self::ENVELOPE_ID);
        $service->notifyEnvelope(self::ENVELOPE_ID);

        Http::assertSentCount(7);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::FAKE_TOKEN)
            || str_contains($request->body(), self::FAKE_TOKEN));
    }

    public function test_test_contract_is_a_real_pdf_without_personal_data(): void
    {
        $contract = new ClicksignTestContract('SNAP-TESTE-1');
        $pdf = $contract->contents();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame('contrato-ensaio-teste-snap-teste-1.pdf', $contract->filename());
    }
}
