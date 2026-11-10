<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\V1\WaiterResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Login do app do garçom: um token por aparelho, revogado ao sair. */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticateWithoutSession();

        if (! $user->hasPermission('tables.operate')) {
            return response()->json(['message' => 'Seu perfil não atende mesas.'], 403);
        }

        return response()->json([
            'token' => $user->createToken($request->validated('device_name'))->plainTextToken,
            'user' => (new WaiterResource($user))->resolve($request),
        ]);
    }

    public function me(Request $request): WaiterResource
    {
        return new WaiterResource($request->user());
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /** Versão mínima do app: abaixo dela o app pede atualização na Play Store. */
    public function app(): JsonResponse
    {
        return response()->json(['min_version_code' => (int) config('garcom.min_version_code')]);
    }
}
