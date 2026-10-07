<?php

namespace App\Services;

use App\Enums\ClicksignEnvelopeStatus;
use App\Enums\ClicksignSignerRole;
use App\Enums\DocumentEventType;
use App\Enums\DocumentSignerStatus;
use App\Enums\DocumentStatus;
use App\Exceptions\ClicksignException;
use App\Exceptions\DocumentActionException;
use App\Exceptions\StorageUnavailableException;
use App\Exceptions\StoredFileNotFoundException;
use App\Models\Document;
use App\Models\DocumentSigner;
use App\Models\User;
use App\Support\FileKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Regras do processo de assinatura de um Document. O ClicksignService fala
 * HTTP com a Clicksign; esta classe decide O QUE fazer e mantém o estado
 * local coerente.
 *
 * Princípios:
 *  - o status local só muda aqui (envio, notificação, sincronização,
 *    webhook, cancelamento) — nunca a partir de dados do front-end;
 *  - cada passo externo do envio é persistido assim que conclui, para que
 *    uma falha no meio deixe rastro suficiente para retomar ou limpar;
 *  - webhook e sincronização passam pelo MESMO applyRemoteEvent(), com as
 *    mesmas chaves de deduplicação: um fato ("fulano assinou") entra no
 *    histórico uma vez só, não importa por qual caminho chegou;
 *  - estados finais (assinado, cancelado, recusado, expirado) não são
 *    desfeitos por eventos atrasados ou fora de ordem.
 */
class DocumentSignatureService
{

    /** Intervalo mínimo entre duas notificações — evita envio duplicado por clique repetido. */
    public const NOTIFY_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly ClicksignService $clicksign,
        private readonly FileStorage $files,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Envio
    |--------------------------------------------------------------------------
    */

    /**
     * Cria o envelope na Clicksign e o ATIVA. Não notifica ninguém: a
     * solicitação de assinatura é uma ação separada (notify()), como na
     * receita oficial.
     *
     * @throws DocumentActionException se o documento não puder ser enviado
     * @throws ClicksignException se alguma chamada externa falhar (o documento fica em integration_error)
     */
    public function send(Document $document, User $user): Document
    {
        // Trava + checagem + mudança de estado numa transação: dois cliques
        // (ou duas abas) no "Enviar" não criam dois envelopes. O segundo
        // encontra o documento já em `preparing` e recebe 409.
        DB::transaction(function () use ($document) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DocumentStatus::Draft) {
                throw new DocumentActionException('Este documento já foi enviado ou está sendo enviado.');
            }

            if ($locked->signers()->count() === 0) {
                throw new DocumentActionException('Adicione ao menos um signatário antes de enviar.', 422);
            }

            // Só PDF vai para a Clicksign: o original, se já era PDF; o
            // convertido, se era Word. Word sem conversão concluída não sai.
            if (! $locked->isReadyForSigning()) {
                throw new DocumentActionException('O documento Word ainda não foi convertido para PDF. Converta e confira o PDF antes de enviar.', 422);
            }

            if (! $this->files->exists($locked->signingFilePath())) {
                throw new DocumentActionException('O arquivo do contrato não foi encontrado. Envie o arquivo novamente.', 422);
            }

            $locked->forceFill([
                'status' => DocumentStatus::Preparing,
                'failed_step' => null,
                'last_error' => null,
            ])->save();
        });

        $document->refresh()->load('signers');
        $step = null;

        // O PDF é lido do armazenamento (local ou S3) ANTES de qualquer
        // chamada à Clicksign: se o armazenamento falhar, nenhum envelope é
        // criado e o documento volta a rascunho, pronto para tentar de novo.
        try {
            $signingPdf = $this->files->get($document->signingFilePath());
        } catch (StoredFileNotFoundException|StorageUnavailableException $e) {
            $document->forceFill(['status' => DocumentStatus::Draft])->save();

            if ($e instanceof StoredFileNotFoundException) {
                throw new DocumentActionException('O arquivo do contrato não foi encontrado. Envie o arquivo novamente.', 422);
            }

            throw $e;
        }

        try {
            $step = 'criar envelope';
            $envelope = $this->clicksign->createEnvelope($this->envelopeName($document), $this->envelopeAttributes($document));
            $document->forceFill(['clicksign_envelope_id' => $envelope['id']])->save();

            $step = 'enviar arquivo';
            $remoteDocument = $this->clicksign->addDocument(
                $document->clicksign_envelope_id,
                $this->remoteFilename($document),
                $signingPdf,
            );
            $document->forceFill(['clicksign_document_id' => $remoteDocument['id']])->save();

            foreach ($document->signers as $signer) {
                $step = "cadastrar signatário {$signer->name}";
                $remoteSigner = $this->clicksign->addSigner($document->clicksign_envelope_id, $this->signerAttributes($signer));
                $signer->forceFill(['clicksign_signer_id' => $remoteSigner['id']])->save();

                $step = "requisitos de {$signer->name}";
                $this->clicksign->addQualificationRequirement(
                    $document->clicksign_envelope_id,
                    $document->clicksign_document_id,
                    $signer->clicksign_signer_id,
                    ClicksignSignerRole::Sign,
                );
                $this->clicksign->addAuthenticationRequirement(
                    $document->clicksign_envelope_id,
                    $document->clicksign_document_id,
                    $signer->clicksign_signer_id,
                    $signer->channel->authMethod(),
                );
            }

            $step = 'ativar envelope';
            $this->clicksign->activateEnvelope($document->clicksign_envelope_id);
        } catch (ClicksignException $e) {
            $this->registerIntegrationError($document, $user, $step, $e);

            throw $e;
        }

        $document->forceFill([
            'status' => DocumentStatus::AwaitingSignature,
            'sent_at' => now(),
        ])->save();

        $document->recordEvent(
            DocumentEventType::Sent,
            'Envelope criado e ativado na Clicksign com '.$document->signers->count().' signatário(s). A solicitação de assinatura ainda não foi enviada.',
            user: $user,
        );

        return $document->refresh();
    }

    /**
     * Desfaz um envio que falhou no meio: exclui o envelope remoto (só é
     * possível enquanto ele estiver em rascunho) e devolve o documento a
     * `draft`, pronto para tentar de novo. O registro local nunca é apagado.
     *
     * @throws DocumentActionException
     * @throws ClicksignException
     */
    public function discardFailedSend(Document $document, User $user): Document
    {
        if ($document->status !== DocumentStatus::IntegrationError) {
            throw new DocumentActionException('Só é possível descartar um envio que falhou.');
        }

        if ($document->clicksign_envelope_id !== null) {
            $remoteStatus = $this->clicksign->getEnvelope($document->clicksign_envelope_id)['attributes']['status'] ?? null;

            if ($remoteStatus !== ClicksignEnvelopeStatus::Draft->value) {
                // O envelope avançou (ex.: a ativação chegou a acontecer,
                // mas a resposta se perdeu). Não dá para excluir: o caminho
                // certo é sincronizar e seguir a partir do estado real.
                throw new DocumentActionException('O envelope já não é um rascunho na Clicksign. Use "Sincronizar status" para recuperar o estado real.');
            }

            $this->clicksign->deleteEnvelope($document->clicksign_envelope_id);
        }

        DB::transaction(function () use ($document, $user) {
            $document->signers()->update(['clicksign_signer_id' => null]);

            $document->forceFill([
                'status' => DocumentStatus::Draft,
                'clicksign_envelope_id' => null,
                'clicksign_document_id' => null,
                'failed_step' => null,
                'last_error' => null,
            ])->save();

            $document->recordEvent(
                DocumentEventType::RemoteDraftDiscarded,
                'Envio interrompido descartado; o rascunho remoto foi excluído e o documento voltou a ser rascunho.',
                user: $user,
            );
        });

        return $document->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Notificação e cancelamento
    |--------------------------------------------------------------------------
    */

    /**
     * Envia (ou reenvia) a solicitação de assinatura a todos os
     * signatários, pelo canal de cada um.
     *
     * @throws DocumentActionException
     * @throws ClicksignException
     */
    public function notify(Document $document, User $user): Document
    {
        $previousNotifiedAt = DB::transaction(function () use ($document) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isInProgress()) {
                throw new DocumentActionException('A solicitação só pode ser enviada enquanto o documento aguarda assinaturas.');
            }

            if ($locked->last_notified_at && $locked->last_notified_at->diffInSeconds(now()) < self::NOTIFY_COOLDOWN_SECONDS) {
                throw new DocumentActionException('A solicitação acabou de ser enviada. Aguarde um minuto antes de reenviar.', 429);
            }

            $previous = $locked->last_notified_at;

            // Marca antes de chamar a API: um segundo clique concorrente já
            // esbarra no intervalo mínimo acima.
            $locked->forceFill(['last_notified_at' => now()])->save();

            return $previous;
        });

        $isResend = $previousNotifiedAt !== null;

        try {
            $this->clicksign->notifyEnvelope($document->clicksign_envelope_id);
        } catch (ClicksignException $e) {
            // A notificação não saiu: devolve a marca anterior, liberando uma
            // nova tentativa imediata. UPDATE direto porque a marca foi
            // gravada por outra instância (a travada na transação).
            Document::whereKey($document->id)->update(['last_notified_at' => $previousNotifiedAt]);

            throw $e;
        }

        $document->refresh()->load('signers');
        $pending = $document->signers->where('status', DocumentSignerStatus::Pending);

        $document->recordEvent(
            DocumentEventType::Notified,
            ($isResend ? 'Solicitação reenviada' : 'Solicitação enviada').' para '.$pending->count().' signatário(s) pendente(s): '
                .$pending->map(fn (DocumentSigner $s) => "{$s->name} ({$s->channel->label()})")->implode(', ').'.',
            ['resend' => $isResend],
            $user,
        );

        return $document;
    }

    /**
     * Cancela o processo de assinatura na Clicksign. Irreversível.
     *
     * @throws DocumentActionException
     * @throws ClicksignException
     */
    public function cancel(Document $document, User $user): Document
    {
        if (! $document->status->isInProgress()) {
            throw new DocumentActionException('Só é possível cancelar um documento que aguarda assinaturas.');
        }

        $this->clicksign->cancelDocument($document->clicksign_envelope_id, $document->clicksign_document_id);

        DB::transaction(function () use ($document, $user) {
            $this->transitionTo($document, DocumentStatus::Canceled);
            $document->recordEvent(DocumentEventType::Canceled, 'Processo de assinatura cancelado no SnapManager.', user: $user, dedupeKey: 'canceled');
        });

        return $document->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Sincronização (manual) e webhook
    |--------------------------------------------------------------------------
    */

    /**
     * Consulta a Clicksign e alinha o estado local: status do envelope +
     * eventos do documento (quem assinou, recusas, cancelamento, prazo).
     *
     * É sempre disparada por um usuário (botão "Sincronizar status"). A
     * documentação da Clicksign proíbe polling automático, então não existe
     * rotina agendada chamando isto.
     *
     * @return array{document: Document, changed: bool}
     *
     * @throws DocumentActionException
     * @throws ClicksignException
     */
    public function sync(Document $document, User $user): array
    {
        if ($document->clicksign_envelope_id === null || $document->clicksign_document_id === null) {
            throw new DocumentActionException('Este documento ainda não foi enviado à Clicksign.');
        }

        $envelope = $this->clicksign->getEnvelope($document->clicksign_envelope_id);
        $remoteEvents = $this->clicksign->listDocumentEvents($document->clicksign_envelope_id, $document->clicksign_document_id);

        $before = [$document->status, $document->signers()->pluck('status', 'id')->all()];

        DB::transaction(function () use ($document, $envelope, $remoteEvents) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();

            // Eventos em ordem cronológica: a API lista do mais recente para o mais antigo.
            $ordered = collect($remoteEvents)->sortBy(fn ($event) => $event['attributes']['created'] ?? '')->values();

            foreach ($ordered as $event) {
                $this->applyRemoteEvent(
                    $document,
                    (string) ($event['attributes']['name'] ?? ''),
                    (array) ($event['attributes']['data'] ?? []),
                    $this->parseTime($event['attributes']['created'] ?? null),
                );
            }

            // O status do envelope é a palavra final sobre abertura/fechamento.
            $remoteStatus = (string) ($envelope['attributes']['status'] ?? '');
            $document->load('signers');
            $target = DocumentStatus::fromRemote($remoteStatus, $document->signedCount());

            if ($target === DocumentStatus::Signed) {
                $this->markDocumentSigned($document, null);
            } elseif ($target === DocumentStatus::Canceled) {
                $this->markDocumentCanceled($document, 'Cancelamento identificado na sincronização.');
            } elseif ($target !== null) {
                $this->transitionTo($document, $target);
            }

            // Recuperação: um envio marcado como falho cujo envelope, na
            // verdade, está ativo (ex.: a ativação aconteceu mas a resposta
            // se perdeu) sai daqui já no estado real, sem o erro antigo.
            if ($document->status->isInProgress() || $document->status->isFinal()) {
                $document->forceFill([
                    'sent_at' => $document->sent_at ?? now(),
                    'failed_step' => null,
                    'last_error' => null,
                ])->save();
            }
        });

        $document->refresh()->load('signers');
        $changed = $before !== [$document->status, $document->signers->pluck('status', 'id')->all()];

        $document->recordEvent(
            DocumentEventType::Synced,
            $changed ? 'Status atualizado a partir da Clicksign: '.$document->status->label().'.' : 'Sincronizado com a Clicksign; nenhuma alteração.',
            ['changed' => $changed, 'remote_status' => $envelope['attributes']['status'] ?? null],
            $user,
        );

        return ['document' => $document, 'changed' => $changed];
    }

    /**
     * Processa um webhook já AUTENTICADO (HMAC validado pelo controller).
     * Devolve um rótulo curto do que aconteceu, para log.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload, ?string $headerEvent = null): string
    {
        $eventName = (string) ($payload['event']['name'] ?? $headerEvent ?? '');

        // A maioria dos eventos traz `document` como objeto; o exemplo
        // oficial de `document_closed` o mostra como lista. Aceita os dois.
        $remoteDocument = $payload['document'] ?? [];
        if (is_array($remoteDocument) && array_is_list($remoteDocument)) {
            $remoteDocument = $remoteDocument[0] ?? [];
        }
        $documentKey = is_array($remoteDocument) ? ($remoteDocument['key'] ?? null) : null;

        if ($eventName === '' || ! is_string($documentKey) || $documentKey === '') {
            return 'ignorado: payload sem evento ou documento';
        }

        // O payload de webhook identifica o DOCUMENTO (document.key), não o
        // envelope — por isso a busca é pelo id do documento na Clicksign.
        $document = Document::where('clicksign_document_id', $documentKey)->first();

        if ($document === null) {
            // Pode ser um documento da conta que não nasceu no SnapManager
            // (ex.: o envelope de homologação). Reconhece e ignora.
            return 'ignorado: documento desconhecido';
        }

        $occurredAt = $this->parseTime($payload['event']['occurred_at'] ?? null)
            ?? $this->parseTime($remoteDocument['finished_at'] ?? null);

        $applied = DB::transaction(function () use ($document, $eventName, $payload, $occurredAt) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();

            return $this->applyRemoteEvent($document, $eventName, (array) ($payload['event']['data'] ?? []), $occurredAt);
        });

        return $applied ? "aplicado: {$eventName}" : "sem efeito: {$eventName}";
    }

    /**
     * Aplica UM evento da Clicksign ao documento local. Compartilhado pelo
     * webhook e pela sincronização. Idempotente: repetir o mesmo evento não
     * duplica histórico nem muda estado.
     *
     * Deve ser chamado dentro de uma transação com o documento travado.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyRemoteEvent(Document $document, string $name, array $data, ?Carbon $at): bool
    {
        return match ($name) {
            'sign' => $this->markSignerSigned($document, $data['signer'] ?? [], $at),
            'refusal' => $this->markSignerDeclined($document, $data['signer'] ?? [], $data['refusal'] ?? []),
            'auto_close', 'close', 'document_closed' => $this->markDocumentSigned($document, $at),
            'cancel' => $this->markDocumentCanceled($document, 'Processo cancelado na Clicksign.'),
            'deadline' => $this->markDocumentExpired($document),
            default => false,
        };
    }

    /** @param  array<string, mixed>  $remoteSigner */
    private function markSignerSigned(Document $document, array $remoteSigner, ?Carbon $at): bool
    {
        $signer = $this->findSigner($document, $remoteSigner);

        if ($signer === null) {
            return false;
        }

        $recorded = $document->recordEvent(
            DocumentEventType::SignerSigned,
            "{$signer->name} assinou o documento.",
            ['signer_id' => $signer->id],
            dedupeKey: 'signer_signed:'.$signer->id,
        );

        if ($signer->status !== DocumentSignerStatus::Signed) {
            $signer->forceFill(['status' => DocumentSignerStatus::Signed, 'signed_at' => $at ?? now()])->save();
            $recorded = true;
        }

        // Uma assinatura NÃO fecha o documento: só o evento de finalização
        // (auto_close/close) ou o envelope `closed` fazem isso.
        if ($document->status === DocumentStatus::AwaitingSignature) {
            $this->transitionTo($document, DocumentStatus::PartiallySigned);
        }

        return $recorded;
    }

    /**
     * @param  array<string, mixed>  $remoteSigner
     * @param  array<string, mixed>  $refusal
     */
    private function markSignerDeclined(Document $document, array $remoteSigner, array $refusal): bool
    {
        $signer = $this->findSigner($document, $remoteSigner);

        if ($signer !== null && $signer->status === DocumentSignerStatus::Pending) {
            $signer->forceFill(['status' => DocumentSignerStatus::Declined])->save();
        }

        $reasons = collect($refusal['reasons'] ?? [])->filter(fn ($r) => is_string($r))->implode(', ');

        $recorded = $document->recordEvent(
            DocumentEventType::SignerDeclined,
            ($signer ? "{$signer->name} recusou o documento." : 'Um signatário recusou o documento.')
                .($reasons !== '' ? ' Motivos: '.Str::limit($reasons, 300).'.' : ''),
            ['signer_id' => $signer?->id],
            dedupeKey: 'declined',
        );

        $this->transitionTo($document, DocumentStatus::Declined);

        return $recorded;
    }

    private function markDocumentSigned(Document $document, ?Carbon $at): bool
    {
        if ($document->status->isFinal() && $document->status !== DocumentStatus::Signed) {
            return false;
        }

        $recorded = $document->recordEvent(
            DocumentEventType::Signed,
            'Documento finalizado na Clicksign: todas as assinaturas concluídas.',
            dedupeKey: 'signed',
        );

        if ($document->status !== DocumentStatus::Signed) {
            $document->forceFill(['status' => DocumentStatus::Signed, 'signed_at' => $at ?? now()])->save();
            $recorded = true;
        }

        return $recorded;
    }

    private function markDocumentCanceled(Document $document, string $description): bool
    {
        if ($document->status->isFinal() && $document->status !== DocumentStatus::Canceled) {
            return false;
        }

        $recorded = $document->recordEvent(DocumentEventType::Canceled, $description, dedupeKey: 'canceled');

        return $this->transitionTo($document, DocumentStatus::Canceled) || $recorded;
    }

    private function markDocumentExpired(Document $document): bool
    {
        if ($document->status->isFinal()) {
            return false;
        }

        $recorded = $document->recordEvent(DocumentEventType::Expired, 'O prazo de assinatura foi atingido.', dedupeKey: 'expired');

        return $this->transitionTo($document, DocumentStatus::Expired) || $recorded;
    }

    /**
     * Muda o status respeitando os estados finais. Devolve true se mudou.
     */
    private function transitionTo(Document $document, DocumentStatus $target): bool
    {
        if ($document->status === $target || $document->status->isFinal()) {
            return false;
        }

        $document->forceFill(['status' => $target])->save();

        return true;
    }

    /**
     * Identifica o signatário local pelo id na Clicksign (o `signer.key` dos
     * eventos é o mesmo id do signatário na API 3.0 — conferido no Sandbox);
     * o e-mail é só um fallback.
     *
     * @param  array<string, mixed>  $remoteSigner
     */
    private function findSigner(Document $document, array $remoteSigner): ?DocumentSigner
    {
        $key = $remoteSigner['key'] ?? null;
        $email = $remoteSigner['email'] ?? null;

        return $document->signers()
            ->when(is_string($key), fn ($q) => $q->where('clicksign_signer_id', $key))
            ->when(! is_string($key) && is_string($email), fn ($q) => $q->whereRaw('lower(email) = ?', [mb_strtolower($email)]))
            ->when(! is_string($key) && ! is_string($email), fn ($q) => $q->whereRaw('1 = 0'))
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Documento assinado
    |--------------------------------------------------------------------------
    */

    /**
     * Object key do PDF assinado. Na primeira vez, baixa da Clicksign e
     * guarda uma cópia no armazenamento persistente (local ou S3) — os links da API expiram em
     * minutos, e a cópia evita depender da Clicksign a cada download.
     *
     * @throws DocumentActionException
     * @throws ClicksignException
     */
    public function signedFilePath(Document $document): string
    {
        if ($document->status !== DocumentStatus::Signed) {
            throw new DocumentActionException('O documento assinado só fica disponível depois que todas as assinaturas forem concluídas.');
        }

        if ($document->signed_file_path && $this->files->exists($document->signed_file_path)) {
            return $document->signed_file_path;
        }

        $remote = $this->clicksign->getDocument($document->clicksign_envelope_id, $document->clicksign_document_id);
        $url = $remote['links']['files']['signed'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new DocumentActionException('A Clicksign ainda não disponibilizou o arquivo assinado. Tente novamente em instantes.');
        }

        $contents = $this->clicksign->downloadFile($url);

        if (! str_starts_with($contents, '%PDF-')) {
            throw new DocumentActionException('O arquivo recebido da Clicksign não é um PDF válido.', 502);
        }

        $path = FileKeys::documentSigned();
        $this->files->put($path, $contents, 'application/pdf');

        $document->forceFill(['signed_file_path' => $path])->save();
        $document->recordEvent(DocumentEventType::SignedFileStored, 'Cópia do documento assinado baixada da Clicksign e armazenada.', dedupeKey: 'signed_file_stored');

        return $path;
    }

    /*
    |--------------------------------------------------------------------------
    | Auxiliares
    |--------------------------------------------------------------------------
    */

    private function registerIntegrationError(Document $document, User $user, ?string $step, ClicksignException $e): void
    {
        // A mensagem da ClicksignException já vem sanitizada (sem token,
        // sem corpo bruto) — ver ClicksignService::errorDetail().
        $message = Str::limit($e->getMessage(), 500);

        $document->forceFill([
            'status' => DocumentStatus::IntegrationError,
            'failed_step' => $step,
            'last_error' => $message,
        ])->save();

        $document->recordEvent(
            DocumentEventType::IntegrationError,
            "Falha na etapa \"{$step}\": {$message}",
            [
                'step' => $step,
                'reason' => $e->reason->value,
                'status' => $e->status,
                'envelope_created' => $document->clicksign_envelope_id !== null,
            ],
            $user,
        );
    }

    private function envelopeName(Document $document): string
    {
        return Str::limit($document->title, 200, '');
    }

    /** @return array<string, mixed> */
    private function envelopeAttributes(Document $document): array
    {
        $attributes = ['locale' => 'pt-BR'];

        // Prazo: fim do dia informado. A API exige data futura e no máximo
        // 90 dias após o upload — um prazo inválido volta como 422 com a
        // mensagem da Clicksign.
        if ($document->expires_at) {
            $attributes['deadline_at'] = $document->expires_at->copy()->endOfDay()->toRfc3339String();
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function signerAttributes(DocumentSigner $signer): array
    {
        return array_filter([
            'name' => $signer->name,
            'email' => $signer->email,
            'phone_number' => $signer->phone_number,
            'communicate_events' => $signer->channel->communicateEvents(),
        ], fn ($value) => $value !== null);
    }

    /**
     * Nome do arquivo enviado à Clicksign: derivado do título (seguro), não
     * do nome original do upload.
     */
    private function remoteFilename(Document $document): string
    {
        return (Str::slug(Str::limit($document->title, 80, '')) ?: 'documento').'.pdf';
    }

    /**
     * Converte um horário vindo da Clicksign para o fuso da aplicação (UTC,
     * convenção do projeto) ANTES de ele chegar ao Eloquent.
     *
     * A Clicksign manda horários com offset (ex.: "2026-09-22T11:57:22-03:00").
     * Carbon::parse mantém o offset -03:00, e o Eloquent grava a data com
     * format('Y-m-d H:i:s') numa coluna `timestamp without time zone` —
     * descartando o offset SEM converter. Resultado: 11:57 de Brasília era
     * gravado como se fosse 11:57 UTC, e a tela (que converte UTC para o
     * horário local) mostrava 08:57, antes do próprio envio. Normalizar
     * aqui corrige webhook e sincronização de uma vez.
     */
    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
