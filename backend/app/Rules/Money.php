<?php

namespace App\Rules;

use App\Support\FieldLimits;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valor em reais, não negativo, com no máximo 2 casas decimais e dentro do
 * que cabe na coluna decimal(10, 2).
 *
 * Mais estrita que "numeric|min:0", que aceita "1e5", "0x1A" (em alguns
 * casos), 3+ casas decimais e valores que estouram a coluna (erro 500 no
 * PostgreSQL). Aceita número JSON (10, 10.5) ou string no formato
 * "1234.56" — com PONTO decimal, sem separador de milhar.
 *
 * O "-0" é normalizado para 0 ANTES da validação (Money::normalize, chamado
 * no prepareForValidation dos Form Requests): matematicamente é zero, e
 * rejeitá-lo só confundiria quem digitou.
 */
class Money implements ValidationRule
{
    private const PATTERN = '/^\d{1,8}(\.\d{1,2})?$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_bool($value) || is_array($value) || is_object($value)) {
            $fail('O valor deve ser um número.');

            return;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                $fail('O valor deve ser um número válido.');

                return;
            }

            // Número JSON: aceito só se já tiver no máximo 2 casas
            // (10.5 → "10.50"; 10.555 → rejeitado). A comparação tolera o
            // erro de representação binária (99999999.99 não é exato em float).
            if (abs(round($value, 2) - $value) > 1e-6) {
                $fail('Informe um valor válido, com até 2 casas decimais (ex.: 1500.00).');

                return;
            }

            $value = sprintf('%.2F', $value);
        }

        $string = (string) $value;

        if (str_starts_with($string, '-')) {
            $fail('O valor não pode ser negativo.');

            return;
        }

        if (preg_match('/^\d+(\.\d+)?$/', $string) && (float) $string > FieldLimits::MONEY_MAX) {
            $fail('O valor máximo permitido é R$ 99.999.999,99.');

            return;
        }

        if (! preg_match(self::PATTERN, $string)) {
            $fail('Informe um valor válido, com até 2 casas decimais (ex.: 1500.00).');
        }
    }

    /**
     * "-0", "-0.0", "-0.00", -0.0 → 0. Strings com espaços nas pontas são
     * aparadas. Qualquer outro valor passa inalterado (a regra decide).
     */
    public static function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            if (preg_match('/^-0+(\.0+)?$/', $value)) {
                return '0';
            }

            return $value;
        }

        if (is_float($value) && $value == 0.0) {
            return 0;
        }

        return $value;
    }
}
