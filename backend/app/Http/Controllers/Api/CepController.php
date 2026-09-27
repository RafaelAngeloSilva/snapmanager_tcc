<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CepController extends Controller
{
    /**
     * Proxies a CEP lookup to ViaCEP.
     *
     * Going through the back-end (instead of calling ViaCEP directly from
     * the front-end) keeps error handling/logging in one place and avoids
     * depending on ViaCEP's CORS policy for the SPA.
     */
    public function show(string $cep): JsonResponse
    {
        $digits = preg_replace('/\D/', '', $cep);

        if (strlen($digits) !== 8) {
            return response()->json([
                'message' => 'CEP inválido. Informe 8 dígitos.',
            ], 422);
        }

        try {
            $response = Http::timeout(5)->get("https://viacep.com.br/ws/{$digits}/json/");
        } catch (\Throwable $e) {
            Log::warning('Falha ao consultar ViaCEP', ['cep' => $digits, 'message' => $e->getMessage()]);

            return response()->json([
                'message' => 'Não foi possível consultar o CEP agora. Preencha o endereço manualmente.',
            ], 503);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Não foi possível consultar o CEP agora. Preencha o endereço manualmente.',
            ], 503);
        }

        $data = $response->json();

        // ViaCEP responde 200 com {"erro": true} para CEP inexistente, em
        // vez de um status HTTP de erro.
        if (! $data || ($data['erro'] ?? false)) {
            return response()->json([
                'message' => 'CEP não encontrado.',
            ], 404);
        }

        return response()->json([
            'zip_code' => $digits,
            'street' => $data['logradouro'] ?? '',
            'neighborhood' => $data['bairro'] ?? '',
            'city' => $data['localidade'] ?? '',
            'state' => $data['uf'] ?? '',
        ]);
    }
}
