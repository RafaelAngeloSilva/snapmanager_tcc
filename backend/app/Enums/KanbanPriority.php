<?php

namespace App\Enums;

/**
 * Prioridade de uma tarefa do quadro de produção. Mesmo padrão dos demais
 * conjuntos fechados do sistema (OrderStatus, AppointmentStatus...): banco
 * guarda o código em varchar, a aplicação trabalha com o enum, a UI mostra
 * o label em português.
 */
enum KanbanPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Baixa',
            self::Medium => 'Média',
            self::High => 'Alta',
            self::Urgent => 'Urgente',
        };
    }
}
