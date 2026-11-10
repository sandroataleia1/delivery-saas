<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Na API: quem foi inativado (ou teve a empresa inativada) perde o token na próxima chamada. */
class EnsureApiAccountCanAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->canSignIn()) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => 'Seu acesso está desativado.'], 401);
        }

        return $next($request);
    }
}
