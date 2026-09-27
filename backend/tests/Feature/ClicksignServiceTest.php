<?php

namespace Tests\Feature;

use App\Enums\ClicksignErrorReason;
use App\Exceptions\ClicksignException;
use App\Services\ClicksignService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Nenhum teste aqui fala com a Clicksign de verdade: todas as respostas vêm
 * do Http::fake(), e preventStrayRequests() faz o teste falhar se alguma
 * requisição escapar do fake. O token usado é fictício — o real do .env
 * é sobrescrito no setUp().
 */
class ClicksignServiceTest extends TestCase
{
    private const FAKE_TOKEN = 'token-ficticio-de-teste-0000-1111';

    private const BASE_URL = 'https://sandbox.clicksign.com/api/v3';

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

    /**
     * Executa a chamada esperando falha e devolve a exceção para inspeção.
     */
    private function captureFailure(callable $call): ClicksignException
    {
        try {
            $call();
        } catch (ClicksignException $e) {
            return $e;
        }

        $this->fail('Era esperada uma ClicksignException.');
    }

    public function test_lists_envelopes_with_json_api_headers_and_raw_token(): void
    {
        Http::fake([
            'sandbox.clicksign.com/api/v3/envelopes*' => Http::response([
                'data' => [],
                'meta' => ['record_count' => 0],
                'links' => ['first' => self::BASE_URL.'/envelopes?page[number]=1'],
            ], 200, ['Content-Type' => ClicksignService::JSON_API_MEDIA_TYPE]),
        ]);

        $service = $this->service();
        $result = $service->listEnvelopes();

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['meta']['record_count']);
        $this->assertSame(200, $service->lastStatus());

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && $request->url() === self::BASE_URL.'/envelopes'
                // API 3.0: token cru, sem "Bearer".
                && $request->header('Authorization') === [self::FAKE_TOKEN]
                && $request->header('Accept') === [ClicksignService::JSON_API_MEDIA_TYPE]
                && $request->header('Content-Type') === [ClicksignService::JSON_API_MEDIA_TYPE];
        });
    }

    public function test_token_is_never_sent_in_the_url(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['record_count' => 0]])]);

        $this->service()->listEnvelopes(['page[number]' => 2]);

        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), self::FAKE_TOKEN)
            && str_contains(urldecode($request->url()), 'page[number]=2'));
    }

    public function test_missing_token_fails_without_any_request(): void
    {
        config(['services.clicksign.access_token' => null]);
        Http::fake();

        $service = $this->service();
        $e = $this->captureFailure(fn () => $service->listEnvelopes());

        $this->assertFalse($service->isConfigured());
        $this->assertSame(ClicksignErrorReason::NotConfigured, $e->reason);
        Http::assertNothingSent();
    }

    public function test_blank_token_is_treated_as_missing(): void
    {
        config(['services.clicksign.access_token' => '   ']);
        Http::fake();

        $e = $this->captureFailure(fn () => $this->service()->listEnvelopes());

        $this->assertSame(ClicksignErrorReason::NotConfigured, $e->reason);
        Http::assertNothingSent();
    }

    public function test_401_becomes_unauthorized(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['status' => '401', 'title' => 'Unauthorized']]], 401)]);

        $e = $this->captureFailure(fn () => $this->service()->listEnvelopes());

        $this->assertSame(ClicksignErrorReason::Unauthorized, $e->reason);
        $this->assertSame(401, $e->status);
        $this->assertStringNotContainsString(self::FAKE_TOKEN, $e->getMessage());
    }

    public function test_403_becomes_forbidden(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['status' => '403', 'detail' => 'Access denied']]], 403)]);

        $e = $this->captureFailure(fn () => $this->service()->listEnvelopes());

        $this->assertSame(ClicksignErrorReason::Forbidden, $e->reason);
        $this->assertSame(403, $e->status);
        $this->assertStringContainsString('Access denied', $e->getMessage());
    }

    public function test_server_error_becomes_unavailable(): void
    {
        Http::fake(['*' => Http::response('<html>Bad Gateway</html>', 502)]);

        $e = $this->captureFailure(fn () => $this->service()->listEnvelopes());

        $this->assertSame(ClicksignErrorReason::Unavailable, $e->reason);
        $this->assertSame(502, $e->status);
        // Corpo bruto (HTML) nunca é repassado para a mensagem.
        $this->assertStringNotContainsString('<html>', $e->getMessage());
    }

    public function test_connection_failure_becomes_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $e = $this->captureFailure(fn () => $this->service()->listEnvelopes());

        $this->assertSame(ClicksignErrorReason::Unavailable, $e->reason);
        $this->assertNull($e->status);
        // A exceção original do Guzzle não é encadeada (ela carrega o request com o header Authorization).
        $this->assertNull($e->getPrevious());
    }

    public function test_other_client_errors_become_request_failed(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['detail' => 'Página inválida']]], 422)]);

        $e = $this->captureFailure(fn () => $this->service()->listEnvelopes());

        $this->assertSame(ClicksignErrorReason::RequestFailed, $e->reason);
        $this->assertSame(422, $e->status);
    }

    public function test_token_echoed_by_the_api_is_redacted_from_the_exception(): void
    {
        Http::fake(['*' => Http::response([
            'errors' => [['detail' => 'Token '.self::FAKE_TOKEN.' is not valid']],
        ], 401)]);

        $e = $this->captureFailure(fn () => $this->service()->listEnvelopes());

        $this->assertStringNotContainsString(self::FAKE_TOKEN, $e->getMessage());
        $this->assertStringContainsString('[token ocultado]', $e->getMessage());
    }

    public function test_failure_log_never_contains_the_token(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['errors' => [['detail' => self::FAKE_TOKEN]]], 401)]);

        $this->captureFailure(fn () => $this->service()->listEnvelopes());

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => ! str_contains($message.json_encode($context), self::FAKE_TOKEN)
                && $context['status'] === 401
                && $context['reason'] === 'unauthorized');
    }

    public function test_check_command_reports_success_without_printing_the_token(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['record_count' => 0]], 200)]);

        $this->artisan('clicksign:check')
            ->expectsOutputToContain('sandbox.clicksign.com')
            ->expectsOutputToContain('HTTP 200')
            ->expectsOutputToContain('Envelopes na conta: 0')
            ->doesntExpectOutputToContain(self::FAKE_TOKEN)
            ->assertSuccessful();
    }

    public function test_check_command_reports_failure_without_printing_the_token(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['detail' => 'Token '.self::FAKE_TOKEN.' revoked']]], 401)]);

        $this->artisan('clicksign:check')
            ->expectsOutputToContain('unauthorized')
            ->doesntExpectOutputToContain(self::FAKE_TOKEN)
            ->assertFailed();
    }

    public function test_check_command_fails_fast_when_not_configured(): void
    {
        config(['services.clicksign.access_token' => null]);
        Http::fake();

        $this->artisan('clicksign:check')
            ->expectsOutputToContain('não configurada')
            ->assertFailed();

        Http::assertNothingSent();
    }
}
