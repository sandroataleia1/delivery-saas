<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Versão do sistema que está no ar. As telas abertas perguntam de tempos em tempos
 * e, quando ela muda, mostram o aviso de atualização.
 */
class AppVersionController extends Controller
{
    public function __invoke(Request $request, HandleInertiaRequests $inertia): JsonResponse
    {
        return response()->json(['version' => $inertia->version($request)])->header('Cache-Control', 'no-store, private');
    }
}
