<?php

namespace App\Enums;

/**
 * Status de produção de um pedido. Coluna `orders.status` continua
 * `varchar` no banco — o enum vive só na camada de aplicação (cast do
 * model, validação, labels), sem exigir ENUM nativo do PostgreSQL.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Captured = 'captured';
    case InEditing = 'in_editing';
    case InReview = 'in_review';
    case ReadyForDelivery = 'ready_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Captured => 'Capturado',
            self::InEditing => 'Em edição',
            self::InReview => 'Em revisão',
            self::ReadyForDelivery => 'Pronto para entrega',
            self::Delivered => 'Entregue',
            self::Cancelled => 'Cancelado',
        };
    }
}
