<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Derruba na próxima requisição quem foi inativado com a sessão aberta. */
class EnsureAccountCanAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->canSignIn()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Seu acesso está desativado.');
        }

        return $next($request);
    }
}
