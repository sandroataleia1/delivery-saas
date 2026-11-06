<?php

namespace App\Reports;

/** Somas do banco chegam como float ou texto: aqui viram sempre duas casas. */
class Money
{
    public static function of(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
