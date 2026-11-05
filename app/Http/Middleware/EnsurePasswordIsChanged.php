<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Obriga a trocar a senha provisória antes de usar o sistema. */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password
            && ! $request->routeIs('account.edit', 'account.password', 'logout')) {
            return redirect()->route('account.edit')
                ->with('error', 'Defina uma nova senha para continuar.');
        }

        return $next($request);
    }
}
