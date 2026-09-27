<?php

namespace App\Console\Commands;

use App\Enums\DocumentEventType;
use App\Enums\DocumentStatus;
use App\Exceptions\ClicksignException;
use App\Models\Document;
use App\Services\ClicksignService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Corrige horários de assinatura gravados antes da correção de fuso em
 * DocumentSignatureService::parseTime (o offset -03:00 da Clicksign era
 * descartado e o horário de Brasília ficava gravado como se fosse UTC).
 *
 * Não "soma 3 horas": recalcula cada horário a partir dos PRÓPRIOS eventos
 * da Clicksign (leitura, GET .../events), normalizados para UTC. Por
 * padrão só mostra o que mudaria; grava apenas com --apply e confirmação.
 * Documentos cujos horários já batem não são tocados.
 */
class RepairDocumentSignedTimes extends Command
{
    protected $signature = 'documents:repair-signed-times {--apply : Grava as correções (sem isso, só simula)}';

    protected $description = 'Recalcula signed_at de documentos assinados a partir dos eventos da Clicksign (simulação por padrão)';

    private const CLOSE_EVENTS = ['auto_close', 'close', 'document_closed'];

    public function handle(ClicksignService $clicksign): int
    {
        $documents = Document::query()
            ->where('status', DocumentStatus::Signed)
            ->whereNotNull('clicksign_envelope_id')
            ->whereNotNull('clicksign_document_id')
            ->with('signers')
            ->get();

        if ($documents->isEmpty()) {
            $this->info('Nenhum documento assinado para conferir.');

            return self::SUCCESS;
        }

        $changes = [];

        foreach ($documents as $document) {
            try {
                $events = collect($clicksign->listDocumentEvents($document->clicksign_envelope_id, $document->clicksign_document_id));
            } catch (ClicksignException $e) {
                $this->warn("Documento #{$document->id}: não foi possível consultar a Clicksign ({$e->getMessage()}). Ignorado.");

                continue;
            }

            $utc = fn (?string $value) => $value ? Carbon::parse($value)->setTimezone(config('app.timezone')) : null;

            // Finalização = o PRIMEIRO evento de fechamento, como na gravação original.
            $closedAt = $events
                ->filter(fn ($e) => in_array($e['attributes']['name'] ?? '', self::CLOSE_EVENTS, true))
                ->map(fn ($e) => $utc($e['attributes']['created'] ?? null))
                ->filter()
                ->sort()
                ->first();

            if ($closedAt && ! $this->sameSecond($document->signed_at, $closedAt)) {
                $changes[] = ['model' => $document, 'label' => "Documento #{$document->id} ({$document->title})", 'from' => $document->signed_at, 'to' => $closedAt];
            }

            foreach ($document->signers as $signer) {
                $signedAt = $events
                    ->filter(fn ($e) => ($e['attributes']['name'] ?? '') === 'sign'
                        && ($e['attributes']['data']['signer']['key'] ?? null) === $signer->clicksign_signer_id)
                    ->map(fn ($e) => $utc($e['attributes']['created'] ?? null))
                    ->filter()
                    ->sort()
                    ->first();

                if ($signedAt && ! $this->sameSecond($signer->signed_at, $signedAt)) {
                    $changes[] = ['model' => $signer, 'label' => "  signatário {$signer->name}", 'from' => $signer->signed_at, 'to' => $signedAt];
                }
            }
        }

        if ($changes === []) {
            $this->info('Todos os horários já conferem com a Clicksign. Nada a corrigir.');

            return self::SUCCESS;
        }

        $this->table(['Registro', 'Gravado hoje (UTC)', 'Correto (UTC)', 'Correto (Brasília)'], array_map(fn ($c) => [
            $c['label'],
            $c['from']?->format('d/m/Y H:i:s') ?? '—',
            $c['to']->format('d/m/Y H:i:s'),
            $c['to']->copy()->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i:s'),
        ], $changes));

        if (! $this->option('apply')) {
            $this->line('Simulação: nada foi gravado. Rode com --apply para corrigir.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Gravar as correções acima?', false)) {
            $this->warn('Cancelado. Nada foi gravado.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($changes) {
            foreach ($changes as $change) {
                $change['model']->forceFill(['signed_at' => $change['to']])->save();
            }

            // Registra no histórico de cada documento o que foi corrigido.
            collect($changes)
                ->groupBy(fn ($c) => $c['model'] instanceof Document ? $c['model']->id : $c['model']->document_id)
                ->each(function ($group, $documentId) {
                    Document::find($documentId)?->recordEvent(
                        DocumentEventType::Synced,
                        'Horários de assinatura recalculados a partir dos eventos da Clicksign (correção de fuso horário).',
                        ['timezone_fix' => true, 'records' => $group->count()],
                    );
                });
        });

        $this->info(count($changes).' horário(s) corrigido(s).');

        return self::SUCCESS;
    }

    private function sameSecond(?Carbon $stored, Carbon $expected): bool
    {
        return $stored !== null && $stored->format('Y-m-d H:i:s') === $expected->format('Y-m-d H:i:s');
    }
}
