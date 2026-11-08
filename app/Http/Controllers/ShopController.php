<?php

namespace App\Http\Controllers;

use App\Actions\PlaceOnlineOrder;
use App\Http\Requests\StoreOnlineOrderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

/** Loja online pública: sem login, sempre filtrando pela empresa dona do slug. */
class ShopController extends Controller
{
    public function store(StoreOnlineOrderRequest $request, string $slug, PlaceOnlineOrder $action): JsonResponse
    {
        $store = $request->onlineStore();
        ['order' => $order, 'token' => $token] = Context::scope(
            fn () => $action->handle($store, $request->validated()),
            hidden: ['audit_actor' => PlaceOnlineOrder::GUEST],
        );

        // A rota nomeada do acompanhamento chega na Task 9; o caminho é montado à mão por enquanto.
        return response()->json([
            'number' => (int) $order->number,
            'tracking_url' => url("/loja/{$store->slug}/pedido/{$token}"),
        ], $order->wasRecentlyCreated ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }
}
