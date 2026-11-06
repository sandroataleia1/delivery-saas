<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Páginas públicas ignoram quem está logado: o escopo de empresa e a autoria da
 * auditoria não podem vir do funcionário que, por acaso, abriu o link.
 */
class ForgetSessionUser
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::guard('web')->forgetUser();

        return $next($request);
    }
}
