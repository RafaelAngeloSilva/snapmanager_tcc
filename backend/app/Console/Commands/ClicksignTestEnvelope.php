<?php

namespace App\Console\Commands;

use App\Enums\ClicksignAuthMethod;
use App\Enums\ClicksignEnvelopeStatus;
use App\Enums\ClicksignSignerRole;
use App\Exceptions\ClicksignException;
use App\Services\ClicksignService;
use App\Support\ClicksignTestContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Homologação do fluxo completo de envelope no Sandbox da Clicksign.
 *
 * Travas de segurança, todas deliberadas:
 *  - só roda contra sandbox.clicksign.com — com qualquer outro host, recusa;
 *  - cada ação externa tem a própria confirmação, e TODAS têm "não" como
 *    padrão: com --no-interaction (ou só apertando Enter), nada é criado,
 *    ativado ou enviado;
 *  - três portões separados: criar o rascunho, ativar, notificar;
 *  - o signatário é sempre informado pelo operador (nenhum e-mail é
 *    inventado ou pré-preenchido);
 *  - se o fluxo quebrar no meio, informa a etapa e oferece excluir o
 *    rascunho criado, para não deixar lixo na conta.
 *
 * Nunca imprime o token — só IDs, status e o host.
 */
class ClicksignTestEnvelope extends Command
{
    public const SANDBOX_HOST = 'sandbox.clicksign.com';

    protected $signature = 'clicksign:test-envelope
                            {--name= : Nome completo do signatário (nome e sobrenome)}
                            {--email= : E-mail do signatário (use um e-mail seu, autorizado para o teste)}';

    protected $description = 'Homologação: cria um envelope de teste no Sandbox da Clicksign (com confirmação a cada etapa)';

    /** @var array<string, string|null> IDs criados até agora — usados no resumo e na limpeza. */
    private array $created = ['envelope' => null, 'document' => null, 'signer' => null];

    public function handle(ClicksignService $clicksign): int
    {
        if (! $clicksign->isConfigured()) {
            $this->error('Integração não configurada: defina CLICKSIGN_BASE_URL e CLICKSIGN_ACCESS_TOKEN no .env.');

            return self::FAILURE;
        }

        if ($clicksign->host() !== self::SANDBOX_HOST) {
            $this->error('Este comando só roda no Sandbox ('.self::SANDBOX_HOST.'). Host configurado: '.($clicksign->host() ?? 'nenhum').'.');

            return self::FAILURE;
        }

        $signer = $this->askSigner();

        if ($signer === null) {
            return self::FAILURE;
        }

        $contract = new ClicksignTestContract;

        $this->newLine();
        $this->line('<options=bold>Plano da homologação</> (ambiente: '.self::SANDBOX_HOST.')');
        $this->table(['Item', 'Valor'], [
            ['Envelope', $this->envelopeName($contract)],
            ['Documento', $contract->filename().' (PDF fictício, sem dados reais)'],
            ['Signatário', "{$signer['name']} <{$signer['email']}>"],
            ['Qualificação', 'agree / '.ClicksignSignerRole::Sign->value],
            ['Autenticação', ClicksignAuthMethod::Email->value.' (token por e-mail)'],
        ]);
        $this->line('Nesta primeira parte o envelope fica como <options=bold>rascunho</>: ninguém recebe nada.');

        if (! $this->confirm('Criar o envelope de teste (rascunho) no Sandbox?', false)) {
            $this->warn('Cancelado. Nenhum recurso foi criado na Clicksign.');

            return self::SUCCESS;
        }

        $step = null;

        try {
            $step = 'criar envelope';
            $envelope = $clicksign->createEnvelope($this->envelopeName($contract), ['locale' => 'pt-BR']);
            $this->created['envelope'] = $envelope['id'];
            $this->done('Envelope criado', $envelope['id'], $envelope['attributes']['status'] ?? null);

            $step = 'enviar documento';
            $document = $clicksign->addDocument($envelope['id'], $contract->filename(), $contract->contents(), ClicksignTestContract::MIME_TYPE);
            $this->created['document'] = $document['id'];
            $this->done('Documento enviado', $document['id'], $document['attributes']['status'] ?? null);

            $step = 'cadastrar signatário';
            $createdSigner = $clicksign->addSigner($envelope['id'], [
                'name' => $signer['name'],
                'email' => $signer['email'],
                // Não pedir CPF na tela de assinatura: o teste não deve
                // coletar documento pessoal.
                'has_documentation' => false,
            ]);
            $this->created['signer'] = $createdSigner['id'];
            $this->done('Signatário cadastrado', $createdSigner['id']);

            $step = 'requisito de qualificação';
            $qualification = $clicksign->addQualificationRequirement($envelope['id'], $document['id'], $createdSigner['id']);
            $this->done('Requisito de qualificação (assinar)', $qualification['id'] ?? null);

            $step = 'requisito de autenticação';
            $authentication = $clicksign->addAuthenticationRequirement($envelope['id'], $document['id'], $createdSigner['id']);
            $this->done('Requisito de autenticação (e-mail)', $authentication['id'] ?? null);
        } catch (ClicksignException $e) {
            return $this->handlePartialFailure($clicksign, $step, $e);
        }

        $this->newLine();
        $this->info('Rascunho completo. Até aqui nada foi enviado ao signatário.');
        $this->line('<fg=yellow>Atenção:</> ativar é irreversível — o envelope deixa de ser rascunho e não pode mais ser excluído,');
        $this->line('só cancelado. A documentação não garante que a ativação não envie e-mail, então considere que');
        $this->line("<options=bold>{$signer['email']}</> pode receber a solicitação de assinatura já neste passo.");

        if (! $this->confirm('ATIVAR o envelope agora (iniciar o processo de assinatura)?', false)) {
            $this->warn('Envelope mantido como rascunho: nada foi enviado.');
            $this->offerDraftCleanup($clicksign);
            $this->summary($clicksign);

            return self::SUCCESS;
        }

        try {
            $step = 'ativar envelope';
            $activated = $clicksign->activateEnvelope($this->created['envelope']);
            $this->done('Envelope ativado', $activated['id'] ?? $this->created['envelope'], $activated['attributes']['status'] ?? null);
        } catch (ClicksignException $e) {
            return $this->handlePartialFailure($clicksign, $step, $e);
        }

        $this->newLine();
        $this->line('A receita oficial da Clicksign trata a notificação como um passo separado da ativação.');
        $this->line('Se o signatário já recebeu e-mail na ativação, esta chamada reenvia a solicitação.');

        if ($this->confirm("Enviar agora a solicitação de assinatura para {$signer['email']}?", false)) {
            try {
                $clicksign->notifyEnvelope($this->created['envelope']);
                $this->done('Notificação enviada', $this->created['envelope']);
            } catch (ClicksignException $e) {
                // O envelope já está ativo: não há rascunho a limpar. Só relata.
                $this->error('Falha ao notificar: '.$e->getMessage());
                $this->line('O envelope continua ativo; a notificação pode ser reenviada pelo painel do Sandbox.');
            }
        } else {
            $this->line('Notificação não enviada por este comando.');
        }

        $this->summary($clicksign);

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, email: string}|null
     */
    private function askSigner(): ?array
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Nome completo do signatário de teste (nome e sobrenome)')));
        $email = trim((string) ($this->option('email') ?: $this->ask('E-mail do signatário (um e-mail seu, autorizado para o teste)')));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            [
                // A Clicksign exige ao menos duas palavras no nome.
                'name' => ['required', 'string', 'max:255', 'regex:/\S+\s+\S+/'],
                'email' => ['required', 'email'],
            ],
            [
                'name.required' => 'Informe o nome do signatário.',
                'name.regex' => 'O nome precisa ter nome e sobrenome (exigência da Clicksign).',
                'email.required' => 'Informe o e-mail do signatário.',
                'email.email' => 'E-mail inválido.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            $this->warn('Nenhum recurso foi criado na Clicksign.');

            return null;
        }

        return ['name' => $name, 'email' => $email];
    }

    private function handlePartialFailure(ClicksignService $clicksign, ?string $step, ClicksignException $e): int
    {
        $this->newLine();
        $this->error("Falha na etapa \"{$step}\": {$e->getMessage()}");
        $this->line('Motivo: '.$e->reason->value);

        if ($this->created['envelope'] === null) {
            $this->warn('Nenhum recurso ficou criado na Clicksign.');

            return self::FAILURE;
        }

        $this->warn('Etapas concluídas antes da falha:');
        $this->summaryTable();
        $this->offerDraftCleanup($clicksign);

        return self::FAILURE;
    }

    /**
     * Rascunho não envia nada a ninguém, mas ocupa a conta. A exclusão só é
     * possível enquanto o envelope estiver em draft.
     */
    private function offerDraftCleanup(ClicksignService $clicksign): void
    {
        $envelopeId = $this->created['envelope'];

        try {
            $status = $clicksign->getEnvelope($envelopeId)['attributes']['status'] ?? null;
        } catch (ClicksignException) {
            $status = null;
        }

        if ($status !== ClicksignEnvelopeStatus::Draft->value) {
            $this->line('O envelope não está em rascunho (status: '.($status ?? 'desconhecido').'), então não pode ser excluído por aqui.');

            return;
        }

        if (! $this->confirm("Excluir o envelope rascunho {$envelopeId}?", false)) {
            $this->line('Rascunho mantido. Ele pode ser consultado ou excluído pelo painel do Sandbox.');

            return;
        }

        try {
            $clicksign->deleteEnvelope($envelopeId);
            $this->info("Rascunho {$envelopeId} excluído.");
            $this->created = ['envelope' => null, 'document' => null, 'signer' => null];
        } catch (ClicksignException $e) {
            $this->error('Não foi possível excluir o rascunho: '.$e->getMessage());
        }
    }

    private function summary(ClicksignService $clicksign): void
    {
        if ($this->created['envelope'] === null) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>Resumo</>');

        try {
            $envelope = $clicksign->getEnvelope($this->created['envelope']);
            $signers = $clicksign->listSigners($this->created['envelope']);
            $requirements = $clicksign->listRequirements($this->created['envelope']);

            $status = ClicksignEnvelopeStatus::tryFrom($envelope['attributes']['status'] ?? '');

            $this->summaryTable([
                ['Status do envelope', $status ? "{$status->value} ({$status->label()})" : '—'],
                ['Signatários no envelope', (string) count($signers)],
                ['Requisitos no envelope', (string) count($requirements)],
            ]);
        } catch (ClicksignException $e) {
            $this->summaryTable();
            $this->warn('Não foi possível consultar o estado atual: '.$e->getMessage());
        }

        $this->line('Link de assinatura: a API 3.0 não devolve URL — o signatário recebe o link por e-mail.');
        $this->line('Acompanhe em https://'.self::SANDBOX_HOST.' (painel do Sandbox).');
    }

    /**
     * @param  array<int, array<int, string>>  $extraRows
     */
    private function summaryTable(array $extraRows = []): void
    {
        $this->table(['Recurso', 'ID / valor'], [
            ['Envelope', $this->created['envelope'] ?? '—'],
            ['Documento', $this->created['document'] ?? '—'],
            ['Signatário', $this->created['signer'] ?? '—'],
            ...$extraRows,
        ]);
    }

    private function done(string $label, ?string $id, ?string $status = null): void
    {
        $this->line("  <fg=green>✓</> {$label}: ".($id ?? '—').($status ? " [{$status}]" : ''));
    }

    private function envelopeName(ClicksignTestContract $contract): string
    {
        return "Teste SnapManager — {$contract->reference}";
    }
}
