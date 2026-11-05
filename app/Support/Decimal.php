<?php

namespace App\Support;

/** Decimais do banco no formato brasileiro, para mensagens e auditoria. */
class Decimal
{
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
