<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Empresa do usuário autenticado.
 *
 * Usa hasUser() e não user(): user() carregaria o modelo User, que também tem o
 * escopo de empresa, e isso entraria em recursão infinita.
 * O middleware auth roda antes do route model binding, então o escopo já está
 * ativo quando as rotas protegidas resolvem seus modelos.
 */
class Tenant
{
    public static function id(): ?int
    {
        return Auth::hasUser() ? Auth::user()->company_id : null;
    }
}
