<?php

namespace App\Enums;

/**
 * Origem de um card. NÃO é uma coluna do banco: é derivada das FKs da
 * própria tarefa (ver KanbanTask::origin()). Existe como enum só para dar
 * um nome tipado ao conceito e servir de filtro na API/UI.
 */
enum KanbanTaskOrigin: string
{
    case Appointment = 'appointment';
    case Order = 'order';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Appointment => 'Agendamento',
            self::Order => 'Pedido',
            self::Manual => 'Manual',
        };
    }
}
