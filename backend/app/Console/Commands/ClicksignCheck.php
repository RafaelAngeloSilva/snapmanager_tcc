<?php

namespace App\Console\Commands;

use App\Exceptions\ClicksignException;
use App\Services\ClicksignService;
use Illuminate\Console\Command;

/**
 * Diagnóstico da integração: autentica na Clicksign e lista os envelopes.
 *
 * É um comando Artisan, e não uma rota, de propósito: só roda quem tem
 * acesso ao servidor, então não existe endpoint de diagnóstico exposto na
 * API. Somente leitura — não cria nem altera nada na conta.
 *
 * Nunca imprime o token: a saída mostra só o host, o status e a contagem.
 */
class ClicksignCheck extends Command
{
    protected $signature = 'clicksign:check';

    protected $description = 'Testa a autenticação com a Clicksign listando os envelopes da conta (somente leitura)';

    public function handle(ClicksignService $clicksign): int
    {
        if (! $clicksign->isConfigured()) {
            $this->error('Integração não configurada: defina CLICKSIGN_BASE_URL e CLICKSIGN_ACCESS_TOKEN no .env.');

            return self::FAILURE;
        }

        $this->line('Consultando GET /envelopes em '.$clicksign->host().' ...');

        try {
            $response = $clicksign->listEnvelopes();
        } catch (ClicksignException $e) {
            $this->error('Falha: '.$e->getMessage());
            $this->line('Motivo: '.$e->reason->value);

            return self::FAILURE;
        }

        $envelopes = $response['data'] ?? [];
        $total = $response['meta']['record_count'] ?? count($envelopes);

        $this->info('Autenticação OK — a Clicksign respondeu HTTP '.$clicksign->lastStatus().'.');
        $this->line("Envelopes na conta: {$total}");

        return self::SUCCESS;
    }
}
