<?php

namespace App\Http\Controllers;

use App\Support\NavCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** O menu consulta de tempos em tempos quantos pedidos esperam a equipe. */
class NavCountController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'online' => NavCounts::online($request->user()),
            'tables' => NavCounts::tables($request->user()),
        ])->header('Cache-Control', 'no-store, private');
    }
}
