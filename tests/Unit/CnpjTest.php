<?php

namespace Tests\Unit;

use App\Rules\Cnpj;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CnpjTest extends TestCase
{
    #[DataProvider('validos')]
    public function test_aceita_cnpj_valido(string $cnpj): void
    {
        $this->assertTrue(Cnpj::isValid($cnpj));
    }

    #[DataProvider('invalidos')]
    public function test_recusa_cnpj_invalido(string $cnpj): void
    {
        $this->assertFalse(Cnpj::isValid($cnpj));
    }

    public static function validos(): array
    {
        return [
            'numérico' => ['11222333000181'],
            'com máscara' => ['11.222.333/0001-81'],
            'alfanumérico' => ['12ABC34501DE35'],
            'alfanumérico com máscara e minúsculas' => ['12.abc.345/01de-35'],
        ];
    }

    public static function invalidos(): array
    {
        return [
            'dígito errado' => ['11222333000182'],
            'alfanumérico com dígito errado' => ['12ABC34501DE36'],
            'repetido' => ['11111111111111'],
            'curto' => ['1122233300018'],
            'letra no verificador' => ['12ABC34501DE3A'],
            'vazio' => [''],
        ];
    }

    public function test_cnpj_gerado_e_valido(): void
    {
        $this->assertTrue(Cnpj::isValid(Cnpj::generate()));
    }
}
