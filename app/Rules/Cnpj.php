<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida o CNPJ numérico e o alfanumérico: 12 caracteres (A-Z, 0-9) e 2 dígitos
 * verificadores. Cada caractere vale seu código ASCII menos 48, por isso os
 * CNPJs numéricos continuam dando o mesmo resultado.
 */
class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value)) {
            $fail('O CNPJ informado não é válido.');
        }
    }

    public static function normalize(?string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    public static function isValid(string $value): bool
    {
        $cnpj = self::normalize($value);

        if (! preg_match('/^[A-Z0-9]{12}\d{2}$/', $cnpj) || preg_match('/^(.)\1{13}$/', $cnpj)) {
            return false;
        }

        return substr($cnpj, 12) === self::checkDigits(substr($cnpj, 0, 12));
    }

    public static function checkDigits(string $base): string
    {
        $first = self::digit($base);

        return $first.self::digit($base.$first);
    }

    /** Gera um CNPJ numérico válido, para testes e dados de exemplo. */
    public static function generate(): string
    {
        $base = str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT).'0001';

        return $base.self::checkDigits($base);
    }

    private static function digit(string $value): int
    {
        $sum = 0;
        $weight = 2;

        foreach (array_reverse(str_split($value)) as $char) {
            $sum += (ord($char) - 48) * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $rest = $sum % 11;

        return $rest < 2 ? 0 : 11 - $rest;
    }
}
