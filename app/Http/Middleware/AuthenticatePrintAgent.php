<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Identifica a empresa pela chave do agente de impressão (guardada só como hash). */
class AuthenticatePrintAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $company = $token === '' ? null : Company::where('print_agent_token', hash('sha256', $token))->where('active', true)->first();

        if (! $company) {
            return response()->json(['message' => 'Agente não autorizado.'], 401);
        }

        $company->forceFill(['print_agent_seen_at' => now()])->saveQuietly();
        $request->attributes->set('company', $company);

        return $next($request);
    }
}
