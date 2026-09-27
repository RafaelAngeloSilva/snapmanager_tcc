<?php

namespace App\Services;

use App\Enums\ClicksignAuthMethod;
use App\Enums\ClicksignEnvelopeStatus;
use App\Enums\ClicksignErrorReason;
use App\Enums\ClicksignSignerRole;
use App\Exceptions\ClicksignException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ponto único de comunicação com a Clicksign (API 3.0).
 *
 * Autenticação, conforme a documentação oficial: o Access Token vai CRU no
 * header `Authorization` (sem o prefixo "Bearer"), e toda requisição usa os
 * headers JSON:API (`application/vnd.api+json`). A API também aceita o token
 * como `?access_token=` na URL, mas isso não é usado aqui de propósito: URL
 * aparece em log de acesso, histórico de proxy e mensagem de exceção; o
 * header não.
 *
 * Toda falha vira ClicksignException com um ClicksignErrorReason — quem
 * chama não precisa conhecer Guzzle, status HTTP nem formato de erro da
 * Clicksign. As próximas operações (documentos, envelopes, signatários)
 * devem passar pelo mesmo send(), herdando autenticação e tratamento de erro.
 */
class ClicksignService
{
    public const JSON_API_MEDIA_TYPE = 'application/vnd.api+json';

    private readonly ?string $baseUrl;

    private readonly ?string $accessToken;

    private readonly int $timeout;

    /** Status HTTP da última resposta recebida (null se nenhuma chegou). */
    private ?int $lastStatus = null;

    public function __construct()
    {
        $baseUrl = config('services.clicksign.base_url');
        $accessToken = config('services.clicksign.access_token');

        $this->baseUrl = is_string($baseUrl) && trim($baseUrl) !== '' ? rtrim(trim($baseUrl), '/') : null;
        $this->accessToken = is_string($accessToken) && trim($accessToken) !== '' ? trim($accessToken) : null;
        $this->timeout = max(1, (int) config('services.clicksign.timeout', 15));
    }

    public function lastStatus(): ?int
    {
        return $this->lastStatus;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== null && $this->accessToken !== null;
    }

    /**
     * Host da API em uso (ex.: "sandbox.clicksign.com"). Útil para
     * diagnóstico — deixa claro se é sandbox ou produção sem expor nada
     * sensível.
     */
    public function host(): ?string
    {
        return $this->baseUrl ? parse_url($this->baseUrl, PHP_URL_HOST) : null;
    }

    /**
     * GET /envelopes — lista os envelopes da conta (paginado, formato
     * JSON:API: `data`, `meta.record_count`, `links`). Lista vazia é uma
     * resposta válida.
     *
     * @param  array<string, mixed>  $query  filtros/paginação JSON:API, ex.: ['page[number]' => 2]
     * @return array<string, mixed>
     *
     * @throws ClicksignException
     */
    public function listEnvelopes(array $query = []): array
    {
        return $this->send('get', '/envelopes', $query)->json() ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | Fluxo de envelope (API 3.0)
    |--------------------------------------------------------------------------
    |
    | Ordem oficial (receita "Criação e configuração do envelope"):
    |   1. createEnvelope                   POST  /envelopes
    |   2. addDocument                      POST  /envelopes/{id}/documents
    |   3. addSigner                        POST  /envelopes/{id}/signers
    |   4. addQualificationRequirement      POST  /envelopes/{id}/requirements  (action: agree)
    |   5. addAuthenticationRequirement     POST  /envelopes/{id}/requirements  (action: provide_evidence)
    |   6. activateEnvelope                 PATCH /envelopes/{id}               (status: running)
    |   7. notifyEnvelope                   POST  /envelopes/{id}/notifications
    |
    | Até o passo 5 o envelope é um rascunho (draft): nada é enviado a
    | ninguém e ele pode ser excluído (deleteEnvelope). A partir da ativação,
    | o processo de assinatura existe de verdade.
    |
    | Os métodos devolvem o recurso `data` da resposta JSON:API (id, type,
    | attributes, links...).
    */

    /**
     * @param  array<string, mixed>  $attributes  opcionais da API: locale, deadline_at, auto_close,
     *                                            remind_interval, block_after_refusal, default_subject, default_message
     * @return array<string, mixed>
     *
     * @throws ClicksignException
     */
    public function createEnvelope(string $name, array $attributes = []): array
    {
        return $this->data($this->send('post', '/envelopes', $this->resource('envelopes', [
            'name' => $name,
            ...$attributes,
        ])));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ClicksignException
     */
    public function getEnvelope(string $envelopeId): array
    {
        return $this->data($this->send('get', $this->envelopePath($envelopeId)));
    }

    /**
     * Anexa um arquivo ao envelope. Recebe o CONTEÚDO bruto do arquivo e
     * monta o `content_base64` no formato exigido pela documentação
     * ("data:<mimetype>;base64,<data>") — sem o prefixo, a API recusa.
     *
     * @return array<string, mixed>
     *
     * @throws ClicksignException
     */
    public function addDocument(string $envelopeId, string $filename, string $contents, string $mimeType = 'application/pdf'): array
    {
        return $this->data($this->send('post', $this->envelopePath($envelopeId, 'documents'), $this->resource('documents', [
            'filename' => $filename,
            'content_base64' => 'data:'.$mimeType.';base64,'.base64_encode($contents),
        ])));
    }

    /**
     * @param  array<string, mixed>  $attributes  name (nome e sobrenome) e email são o mínimo; opcionais da API:
     *                                            phone_number, birthday, documentation, has_documentation, refusable,
     *                                            group, location_required_enabled, communicate_events
     * @return array<string, mixed>
     *
     * @throws ClicksignException
     */
    public function addSigner(string $envelopeId, array $attributes): array
    {
        return $this->data($this->send('post', $this->envelopePath($envelopeId, 'signers'), $this->resource('signers', $attributes)));
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ClicksignException
     */
    public function listSigners(string $envelopeId): array
    {
        return $this->send('get', $this->envelopePath($envelopeId, 'signers'))->json('data') ?? [];
    }

    /**
     * Requisito de qualificação: o que o signatário faz no documento
     * (`action: agree` + papel).
     *
     * @return array<string, mixed>
     *
     * @throws ClicksignException
     */
    public function addQualificationRequirement(
        string $envelopeId,
        string $documentId,
        string $signerId,
        ClicksignSignerRole $role = ClicksignSignerRole::Sign,
    ): array {
        return $this->addRequirement($envelopeId, $documentId, $signerId, [
            'action' => 'agree',
            'role' => $role->value,
        ]);
    }

    /**
     * Requisito de autenticação: como o signatário comprova quem é
     * (`action: provide_evidence` + método).
     *
     * @return array<string, mixed>
     *
     * @throws ClicksignException
     */
    public function addAuthenticationRequirement(
        string $envelopeId,
        string $documentId,
        string $signerId,
        ClicksignAuthMethod $auth = ClicksignAuthMethod::Email,
    ): array {
        return $this->addRequirement($envelopeId, $documentId, $signerId, [
            'action' => 'provide_evidence',
            'auth' => $auth->value,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ClicksignException
     */
    public function listRequirements(string $envelopeId): array
    {
        return $this->send('get', $this->envelopePath($envelopeId, 'requirements'))->json('data') ?? [];
    }

    /**
     * Ativa o envelope (draft → running). Irreversível: a partir daqui o
     * processo de assinatura existe e o envelope não pode mais ser excluído,
     * só cancelado. A documentação não garante que a ativação NÃO dispare
     * e-mail ao signatário — trate a chamada como envio.
     *
     * @return array<string, mixed>
     *
     * @throws ClicksignException
     */
    public function activateEnvelope(string $envelopeId): array
    {
        return $this->data($this->send('patch', $this->envelopePath($envelopeId), $this->resource(
            'envelopes',
            ['status' => ClicksignEnvelopeStatus::Running->value],
            id: $envelopeId,
        )));
    }

    /**
     * Envia aos signatários a solicitação de assinatura (e-mail/WhatsApp/SMS,
     * conforme `communicate_events` de cada um). É por aqui que o signatário
     * recebe o link: a API 3.0 não devolve URL de assinatura.
     *
     * @return array<string, mixed>
     *
     * @throws ClicksignException
     */
    public function notifyEnvelope(string $envelopeId, ?string $message = null): array
    {
        return $this->data($this->send('post', $this->envelopePath($envelopeId, 'notifications'), $this->resource(
            'notifications',
            $message !== null ? ['message' => $message] : [],
        )));
    }

    /**
     * Exclui um envelope. A API só permite para envelopes em rascunho
     * (draft); em outro estado responde 403.
     *
     * @throws ClicksignException
     */
    public function deleteEnvelope(string $envelopeId): void
    {
        $this->send('delete', $this->envelopePath($envelopeId));
    }

    /**
     * GET /envelopes/{id}/documents/{id} — inclui `links.files`, com URLs
     * temporárias (pré-assinadas, ~5 min) para `original`, `signed` e
     * `ziped`. `signed` só existe depois que o documento é finalizado.
     *
     * @return array<string, mixed>
     *
     * @throws ClicksignException
     */
    public function getDocument(string $envelopeId, string $documentId): array
    {
        return $this->data($this->send('get', $this->documentPath($envelopeId, $documentId)));
    }

    /**
     * GET /envelopes/{id}/documents/{id}/events — histórico do documento na
     * Clicksign (upload, sign, refusal, auto_close, cancel...). É daqui que
     * a sincronização descobre quem assinou: o recurso de signatário da API
     * 3.0 não tem campo de status.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ClicksignException
     */
    public function listDocumentEvents(string $envelopeId, string $documentId): array
    {
        return $this->send('get', $this->documentPath($envelopeId, $documentId).'/events')->json('data') ?? [];
    }

    /**
     * Cancela o processo de assinatura. Na API 3.0 o cancelamento é feito no
     * DOCUMENTO (o PATCH do envelope só aceita draft/running), e só vale
     * para documentos `running`. Irreversível.
     *
     * @return array<string, mixed>
     *
     * @throws ClicksignException
     */
    public function cancelDocument(string $envelopeId, string $documentId): array
    {
        return $this->data($this->send('patch', $this->documentPath($envelopeId, $documentId), $this->resource(
            'documents',
            ['status' => 'canceled'],
            id: $documentId,
        )));
    }

    /**
     * Baixa um arquivo a partir de uma URL temporária devolvida pela API
     * (`links.files.*`).
     *
     * Essas URLs são pré-assinadas pelo próprio storage da Clicksign: a
     * autorização está na URL, então a requisição sai SEM o header
     * Authorization — o Access Token nunca é enviado a um host que não seja
     * a API. Só aceita HTTPS.
     *
     * @throws ClicksignException
     */
    public function downloadFile(string $url): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new ClicksignException(ClicksignErrorReason::RequestFailed, detail: 'URL de arquivo inválida.');
        }

        try {
            $response = Http::connectTimeout(min(5, $this->timeout))->timeout(max(30, $this->timeout))->get($url);
        } catch (ConnectionException) {
            $this->logFailure('get', '[arquivo]', null, ClicksignErrorReason::Unavailable);

            throw new ClicksignException(ClicksignErrorReason::Unavailable, detail: 'Não foi possível baixar o arquivo.');
        }

        if (! $response->successful()) {
            $reason = $response->serverError() ? ClicksignErrorReason::Unavailable : ClicksignErrorReason::RequestFailed;
            $this->logFailure('get', '[arquivo]', $response->status(), $reason);

            // O link pode ter expirado (~5 min): quem chama deve pedir um novo.
            throw new ClicksignException($reason, $response->status(), 'Não foi possível baixar o arquivo.');
        }

        return $response->body();
    }

    private function documentPath(string $envelopeId, string $documentId): string
    {
        return $this->envelopePath($envelopeId, 'documents/'.rawurlencode($documentId));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function addRequirement(string $envelopeId, string $documentId, string $signerId, array $attributes): array
    {
        return $this->data($this->send('post', $this->envelopePath($envelopeId, 'requirements'), $this->resource(
            'requirements',
            $attributes,
            relationships: [
                'document' => ['data' => ['type' => 'documents', 'id' => $documentId]],
                'signer' => ['data' => ['type' => 'signers', 'id' => $signerId]],
            ],
        )));
    }

    /**
     * Monta o corpo JSON:API: {"data": {"type", "id"?, "attributes", "relationships"?}}.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $relationships
     * @return array<string, mixed>
     */
    private function resource(string $type, array $attributes, array $relationships = [], ?string $id = null): array
    {
        $data = ['type' => $type];

        if ($id !== null) {
            $data['id'] = $id;
        }

        // JSON:API espera objeto em "attributes"; array vazio viraria [] no JSON.
        $data['attributes'] = $attributes === [] ? new \stdClass : $attributes;

        if ($relationships !== []) {
            $data['relationships'] = $relationships;
        }

        return ['data' => $data];
    }

    /**
     * IDs vão para a URL codificados: um id vindo de input (ex.: do comando
     * de homologação) nunca consegue alterar o caminho da requisição.
     */
    private function envelopePath(string $envelopeId, ?string $subresource = null): string
    {
        $path = '/envelopes/'.rawurlencode($envelopeId);

        return $subresource ? "{$path}/{$subresource}" : $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        return $response->json('data') ?? [];
    }

    /**
     * Executa uma requisição autenticada e devolve a resposta só se ela for
     * bem-sucedida (2xx). Qualquer outro desfecho vira ClicksignException.
     *
     * @param  array<string, mixed>  $payload  query string (GET) ou corpo JSON:API (POST/PATCH)
     *
     * @throws ClicksignException
     */
    protected function send(string $method, string $path, array $payload = []): Response
    {
        if (! $this->isConfigured()) {
            // Falha antes de abrir conexão: sem token não há o que tentar.
            throw new ClicksignException(ClicksignErrorReason::NotConfigured);
        }

        try {
            $response = $this->client()->send(strtoupper($method), ltrim($path, '/'), $this->options($method, $payload));
        } catch (ConnectionException $e) {
            // Não encadeia a exceção original ($previous): o objeto de
            // request do Guzzle carrega o header Authorization, e um stack
            // trace serializado em log poderia expô-lo. Guarda-se só a
            // mensagem, já sanitizada.
            $this->logFailure($method, $path, null, ClicksignErrorReason::Unavailable);

            throw new ClicksignException(
                ClicksignErrorReason::Unavailable,
                detail: $this->redact($e->getMessage()),
            );
        }

        $this->lastStatus = $response->status();

        if ($response->successful()) {
            return $response;
        }

        $reason = match (true) {
            $response->status() === 401 => ClicksignErrorReason::Unauthorized,
            $response->status() === 403 => ClicksignErrorReason::Forbidden,
            $response->serverError() => ClicksignErrorReason::Unavailable,
            default => ClicksignErrorReason::RequestFailed,
        };

        $this->logFailure($method, $path, $response->status(), $reason);

        throw new ClicksignException($reason, $response->status(), $this->errorDetail($response));
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl.'/')
            ->withHeaders(['Authorization' => $this->accessToken])
            ->accept(self::JSON_API_MEDIA_TYPE)
            ->contentType(self::JSON_API_MEDIA_TYPE)
            ->connectTimeout(min(5, $this->timeout))
            ->timeout($this->timeout);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function options(string $method, array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        return strtolower($method) === 'get' ? ['query' => $payload] : ['json' => $payload];
    }

    /**
     * Extrai uma explicação legível do erro no formato JSON:API
     * (`errors[].detail` ou `errors[].title`), limitada em tamanho e sem o
     * token — nunca repassa o corpo bruto da resposta.
     */
    private function errorDetail(Response $response): ?string
    {
        $errors = $response->json('errors');

        if (! is_array($errors) || $errors === []) {
            return null;
        }

        $detail = collect($errors)
            ->map(fn ($error) => is_array($error) ? ($error['detail'] ?? $error['title'] ?? null) : null)
            ->filter(fn ($text) => is_string($text) && $text !== '')
            ->implode('; ');

        return $detail === '' ? null : $this->redact(mb_strimwidth($detail, 0, 300, '...'));
    }

    /**
     * Defesa em profundidade: se por algum motivo o token aparecer num texto
     * que vai para mensagem de exceção ou log, ele é mascarado.
     */
    private function redact(string $text): string
    {
        return $this->accessToken ? str_replace($this->accessToken, '[token ocultado]', $text) : $text;
    }

    private function logFailure(string $method, string $path, ?int $status, ClicksignErrorReason $reason): void
    {
        Log::warning('Falha na comunicação com a Clicksign.', [
            'method' => strtoupper($method),
            'path' => $path,
            'status' => $status,
            'reason' => $reason->value,
        ]);
    }
}
