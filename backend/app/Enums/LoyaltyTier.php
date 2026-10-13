<?php

namespace App\Enums;

/**
 * Selo de fidelidade — calculado, nunca gravado no banco.
 *
 * Conta só ensaios CONCLUÍDOS (status completed, não excluídos e com data
 * já passada). Faixas sem sobreposição:
 *   0 a 2  → Novo   (inclui quem ainda não fez nenhum ensaio: o cliente
 *                     acabou de chegar, e um selo vazio pareceria erro)
 *   3 a 5  → Fiel
 *   6 ou + → VIP
 */
enum LoyaltyTier: string
{
    case New = 'new';
    case Loyal = 'loyal';
    case Vip = 'vip';

    public const LOYAL_FROM = 3;

    public const VIP_FROM = 6;

    public static function fromCompletedShoots(int $completed): self
    {
        return match (true) {
            $completed >= self::VIP_FROM => self::Vip,
            $completed >= self::LOYAL_FROM => self::Loyal,
            default => self::New,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'Novo',
            self::Loyal => 'Fiel',
            self::Vip => 'VIP',
        };
    }
}
