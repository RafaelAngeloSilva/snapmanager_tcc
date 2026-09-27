<?php

namespace App\Enums;

/**
 * Estados de um envelope na Clicksign API 3.0 (documentação oficial:
 * draft, running, closed, canceled). Só `draft` aceita alterações e
 * exclusão; `running` é o envelope ativado, aguardando assinaturas.
 */
enum ClicksignEnvelopeStatus: string
{
    case Draft = 'draft';
    case Running = 'running';
    case Closed = 'closed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Running => 'Em andamento',
            self::Closed => 'Finalizado',
            self::Canceled => 'Cancelado',
        };
    }
}
