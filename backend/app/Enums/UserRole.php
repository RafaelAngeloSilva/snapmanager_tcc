<?php

namespace App\Enums;

/**
 * Perfis de usuário interno. O banco continua guardando o valor textual
 * (`role` é `varchar`) — este enum só centraliza o conjunto fechado de
 * valores válidos e evita strings mágicas espalhadas pelo código.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Attendant = 'attendant';
    case Photographer = 'photographer';
    case Editor = 'editor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Attendant => 'Atendimento',
            self::Photographer => 'Fotógrafo',
            self::Editor => 'Editor',
        };
    }
}
