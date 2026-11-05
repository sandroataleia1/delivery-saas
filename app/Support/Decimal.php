<?php

namespace App\Support;

/** Decimais do banco no formato brasileiro, para mensagens e auditoria. */
class Decimal
{
    /** Arredonda para centavos, com meio centavo para cima. Só para valores positivos. */
    public static function money(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }

    /** "40.5" vira "40,50": valor em reais, sempre com centavos. */
    public static function currency(string|int|float|null $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    /** "4.000" vira "4" e "2.500" vira "2,5": "4.000" seria lido como quatro mil. */
    public static function br(string|int|float|null $value): string
    {
        $text = (string) $value;

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return str_replace('.', ',', $text);
    }
}
