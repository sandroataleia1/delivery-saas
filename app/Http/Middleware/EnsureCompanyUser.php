<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Área da empresa: o super administrador não tem empresa e vai para o painel dele. */
class EnsureCompanyUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->company_id === null) {
            return redirect()->route('admin.companies.index');
        }

        return $next($request);
    }
}
