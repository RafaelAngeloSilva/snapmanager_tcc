<?php

namespace App\Enums;

/**
 * Categorias das anotações MANUAIS da linha do tempo do cliente.
 */
enum ClientHistoryCategory: string
{
    case FirstContact = 'first_contact';
    case Briefing = 'briefing';
    case ShootDay = 'shoot_day';
    case PostSale = 'post_sale';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::FirstContact => 'Primeiro contato',
            self::Briefing => 'Briefing / Reunião',
            self::ShootDay => 'Dia do ensaio',
            self::PostSale => 'Pós-venda',
            self::General => 'Observação geral',
        };
    }

    /** Filtro da linha do tempo em que a anotação aparece. */
    public function group(): string
    {
        return match ($this) {
            self::ShootDay => 'shoots',
            self::PostSale => 'post_sale',
            default => 'service',
        };
    }
}
