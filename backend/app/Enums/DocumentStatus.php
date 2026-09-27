<?php

namespace App\Enums;

/**
 * Estado LOCAL de um documento. Não é cópia do status da Clicksign: ele
 * combina o estado do envelope com o dos signatários e com o que aconteceu
 * do nosso lado (ex.: um envio que falhou no meio).
 *
 * Mapeamento com a Clicksign (ver fromRemote()):
 *
 *   local                 Clicksign
 *   --------------------  ------------------------------------------------
 *   draft                 (nada criado na Clicksign ainda)
 *   preparing             envelope em `draft`, envio em andamento
 *   integration_error     envio interrompido; envelope pode ter ficado em `draft`
 *   awaiting_signature    envelope `running`, nenhuma assinatura
 *   partially_signed      envelope `running`, ao menos uma assinatura
 *   signed                envelope/documento `closed`  <- SÓ isto é conclusão
 *   canceled              documento `canceled`
 *   declined              evento `refusal` (signatário recusou)
 *   expired               evento `deadline` (prazo atingido sem finalizar)
 *
 * Todas as assinaturas coletadas NÃO significam `signed`: só o fechamento
 * do envelope (`closed`) representa conclusão integral. Com auto_close
 * (padrão da Clicksign) o fechamento vem logo após a última assinatura.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Preparing = 'preparing';
    case AwaitingSignature = 'awaiting_signature';
    case PartiallySigned = 'partially_signed';
    case Signed = 'signed';
    case Declined = 'declined';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case IntegrationError = 'integration_error';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Preparing => 'Preparando envio',
            self::AwaitingSignature => 'Aguardando assinatura',
            self::PartiallySigned => 'Assinado parcialmente',
            self::Signed => 'Assinado',
            self::Declined => 'Recusado',
            self::Canceled => 'Cancelado',
            self::Expired => 'Expirado',
            self::IntegrationError => 'Erro de integração',
        };
    }

    /** Estados finais: nenhum evento posterior (webhook/sync) tira o documento deles. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Signed, self::Declined, self::Canceled, self::Expired], true);
    }

    /** O processo de assinatura está aberto na Clicksign. */
    public function isInProgress(): bool
    {
        return in_array($this, [self::AwaitingSignature, self::PartiallySigned], true);
    }

    /** Conteúdo (dados, arquivo, signatários) ainda pode ser alterado. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Traduz o status do envelope na Clicksign, somado à contagem de
     * assinaturas, para o estado local. Devolve null quando o status remoto
     * não decide nada por si só (ex.: `draft`).
     */
    public static function fromRemote(string $remoteStatus, int $signedCount): ?self
    {
        return match ($remoteStatus) {
            ClicksignEnvelopeStatus::Closed->value => self::Signed,
            ClicksignEnvelopeStatus::Canceled->value => self::Canceled,
            ClicksignEnvelopeStatus::Running->value => $signedCount > 0 ? self::PartiallySigned : self::AwaitingSignature,
            default => null,
        };
    }
}
