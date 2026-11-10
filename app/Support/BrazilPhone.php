<?php

namespace App\Support;

/** Telefone brasileiro com DDD, guardado só com dígitos. */
class BrazilPhone
{
    /**
     * Devolve 10 ou 11 dígitos, sem o 55 do país, ou null quando não há DDD válido.
     */
    public static function normalize(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        return in_array(strlen($digits), [10, 11], true) ? $digits : null;
    }
}
