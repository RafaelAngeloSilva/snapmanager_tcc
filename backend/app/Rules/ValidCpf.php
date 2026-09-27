<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;


class ValidCpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) === 14) {
            return;
        }

        if (strlen($digits) !== 11) {
            $fail('Documento inválido. Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.');

            return;
        }

        if (! self::isValidCpf($digits)) {
            $fail('CPF inválido. Verifique os dígitos informados.');
        }
    }

    /**
     * Standard CPF check-digit algorithm: each verifier digit is the
     * remainder of a weighted sum (weights 10..2, then 11..2) mod 11,
     * mapped to 0 when the remainder is 0 or 1.
     */
    public static function isValidCpf(string $digits): bool
    {
        if (strlen($digits) !== 11) {
            return false;
        }

        // Sequences like 000.000.000-00 or 111.111.111-11 pass the weighted-sum
        // formula by construction, so they must be excluded explicitly.
        if (preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        $calculateDigit = function (string $base, int $startWeight) {
            $sum = 0;

            foreach (str_split($base) as $index => $digit) {
                $sum += (int) $digit * ($startWeight - $index);
            }

            $remainder = $sum % 11;

            return $remainder < 2 ? 0 : 11 - $remainder;
        };

        $firstDigit = $calculateDigit(substr($digits, 0, 9), 10);
        $secondDigit = $calculateDigit(substr($digits, 0, 9).$firstDigit, 11);

        return $digits === substr($digits, 0, 9).$firstDigit.$secondDigit;
    }
}
