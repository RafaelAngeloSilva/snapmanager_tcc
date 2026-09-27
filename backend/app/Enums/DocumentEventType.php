<?php

namespace App\Enums;

/**
 * Tipos de entrada no histórico do documento (document_events.event).
 */
enum DocumentEventType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case FileReplaced = 'file_replaced';
    case SignerAdded = 'signer_added';
    case SignerRemoved = 'signer_removed';
    case Sent = 'sent';
    case Notified = 'notified';
    case SignerSigned = 'signer_signed';
    case SignerDeclined = 'signer_declined';
    case Signed = 'signed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Synced = 'synced';
    case IntegrationError = 'integration_error';
    case RemoteDraftDiscarded = 'remote_draft_discarded';
    case SignedFileStored = 'signed_file_stored';
    case FileConverted = 'file_converted';
    case ConversionFailed = 'conversion_failed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Documento criado',
            self::Updated => 'Dados atualizados',
            self::FileReplaced => 'Arquivo substituído',
            self::SignerAdded => 'Signatário adicionado',
            self::SignerRemoved => 'Signatário removido',
            self::Sent => 'Enviado para assinatura',
            self::Notified => 'Solicitação de assinatura enviada',
            self::SignerSigned => 'Signatário assinou',
            self::SignerDeclined => 'Signatário recusou',
            self::Signed => 'Documento finalizado',
            self::Canceled => 'Processo cancelado',
            self::Expired => 'Prazo expirado',
            self::Synced => 'Status sincronizado',
            self::IntegrationError => 'Erro de integração',
            self::RemoteDraftDiscarded => 'Envio descartado',
            self::SignedFileStored => 'Documento assinado armazenado',
            self::FileConverted => 'Word convertido para PDF',
            self::ConversionFailed => 'Falha na conversão do Word',
        };
    }
}
