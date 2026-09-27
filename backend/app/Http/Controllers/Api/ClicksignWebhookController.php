<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/webhooks/clicksign — chamado pela Clicksign, não por um usuário.
 *
 * Por isso não passa por auth:sanctum: a autenticidade vem do HMAC,
 * conforme "Segurança de Webhooks" da Clicksign:
 *  - header `Content-Hmac: sha256=<hex>`;
 *  - <hex> = HMAC-SHA256 do CORPO BRUTO da requisição com o secret do
 *    webhook (gerado pela Clicksign ao cadastrar o webhook);
 *  - o cálculo usa os bytes exatamente como chegaram — nunca o JSON
 *    re-serializado, que mudaria espaços/ordem e invalidaria a assinatura.
 *
 * Sem secret configurado, ou com HMAC ausente/inválido, NADA é processado.
 * O processamento em si é só banco local (sem chamadas externas), então a
 * resposta 2xx sai rápido, como a Clicksign recomenda.
 */
class ClicksignWebhookController extends Controller
{
    public function __invoke(Request $request, DocumentSignatureService $signatures): JsonResponse
    {
        $secret = config('services.clicksign.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            Log::warning('Webhook da Clicksign recebido, mas CLICKSIGN_WEBHOOK_SECRET não está configurado. Ignorado.');

            return response()->json(['message' => 'Webhook não configurado.'], 503);
        }

        $rawBody = $request->getContent();
        $received = (string) $request->header('Content-Hmac', '');
        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        // hash_equals: comparação em tempo constante (não vaza, por tempo de
        // resposta, quantos caracteres do HMAC estavam certos).
        if ($received === '' || ! hash_equals($expected, $received)) {
            Log::warning('Webhook da Clicksign com HMAC inválido. Ignorado.', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Assinatura inválida.'], 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Payload inválido.'], 400);
        }

        $outcome = $signatures->handleWebhook($payload, $request->header('Event'));

        Log::info('Webhook da Clicksign processado.', [
            'event' => $payload['event']['name'] ?? $request->header('Event'),
            'outcome' => $outcome,
        ]);

        return response()->json(['received' => true]);
    }
}
